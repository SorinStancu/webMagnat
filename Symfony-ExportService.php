<?php
namespace AppBundle\Services;

use AppBundle\Entity\Listing;
use AppBundle\Entity\RentPayment;
use AppBundle\Entity\Tenant;
use AppBundle\Entity\User;
use AppBundle\EventListener\ExceptionListener;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\Annotation as Serializer;
use JMS\Serializer\Annotation\SerializedName;
use JMS\Serializer\Annotation\Type;
use JMS\Serializer\Annotation\XmlElement;
use JMS\Serializer\Annotation\XmlRoot;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Wamania\ZipStreamedResponseBundle\Response\ZipStreamer\ZipStreamer;

class ExportService
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /** @var Logger $loggerDb */
    protected $loggerDb;

    /** @var SettingsService $settings */
    protected $settings;

    /** @var AmazonS3Service $s3 */
    protected $s3;

    /** @var SerializerInterface $serializer */
    protected $serializer;

    public function __construct(ContainerInterface $container, EntityManagerInterface $em, SettingsService $settings)
    {
        $this->em = $em;
        $this->settings = $settings;
        $this->loggerDb = $container->get('monolog.logger.db');
        $this->s3 = $container->get('s3storage');
        $this->serializer = $container->get('jms_serializer');
    }

    public function exportZip(string $startDate, string $endDate): ZipStreamer {
        $this->em->getFilters()->disable('softdeleteable');

        try {
            $bag = new ExportZipfilesProcessBag();

            $bag->startDate = $startDate;
            $bag->endDate = $endDate;

            $bag->zipstreamer = new ZipStreamer("milluu_invoices_${startDate}_$endDate.zip");
            $bag->bills = [];
            $bag->invoiceLog = new InvoiceLog();

            // gather bills
            $this->processByInvoiceDate($this->em, $bag);
            $this->processByPenaltyInvoiceDate($this->em, $bag);

            // download from S3 and bundle in zip
            foreach ($bag->bills as $bill) {
                $filename = str_replace(AmazonS3Service::URL_PREFIX . 'bills/', '', $bill->uri);

                $fileExists = $this->s3->checkFileExist('bills/'.$filename);
                if (!$fileExists) {
                    $bill->error = "File doesn't exist on S3";
                    continue;
                }

                copy($bill->uri, $filename);
                $bag->zipstreamer->add($filename, $filename);
                unlink($filename);
            }

            return $bag->zipstreamer;
        }
        finally {
            $this->em->getFilters()->enable('softdeleteable');
        }
    }

    public function exportXml(string $startDate, string $endDate) {
        $this->em->getFilters()->disable('softdeleteable');

        try {
            $bag = new ExportXmlInfoProcessBag();

            $bag->startDate = $startDate;
            $bag->endDate = $endDate;

            $bag->invoiceLog = new InvoiceLog();

            // gather bills
            $this->processByInvoiceDate($this->em, $bag);
            $this->processByPenaltyInvoiceDate($this->em, $bag);

            $rt = new Facturi();
            $rt->facturi = $bag->facturi;

            usort($rt->facturi, function (Factura $a, Factura $b) {
//                $format = "d.m.Y";
//                $d1 = DateTime::createFromFormat($format, $a->antet->FacturaData);
//                $d2 = DateTime::createFromFormat($format, $b->antet->FacturaData);
//                if ($d1 !== $d2) {
//                    return $d1 > $d2 ? -1 : 1;
//                }

                $int_value1 = (int) $a->antet->FacturaID;
                $int_value2 = (int) $b->antet->FacturaID;
                return $int_value1 < $int_value2 ? -1 : 1;
            });

            $context = SerializationContext::create();
            $dt = $this->serializer->serialize($rt, 'xml', $context);

            return $dt;
        }
        finally {
            $this->em->getFilters()->enable('softdeleteable');
        }
    }

    public function exportXmlStats(string $startDate, string $endDate) {
        $this->em->getFilters()->disable('softdeleteable');

        try {
            $bag = new ExportXmlInfoProcessBag();

            $bag->startDate = $startDate;
            $bag->endDate = $endDate;

            $bag->invoiceLog = new InvoiceLog();
            $bag->invoiceLog->counters = [];

            // gather bills
            $this->processByInvoiceDate($this->em, $bag);
            $this->processByPenaltyInvoiceDate($this->em, $bag);

            $rt = new Facturi();
            $rt->facturi = $bag->facturi;

            usort($rt->facturi, function (Factura $a, Factura $b) {
                $int_value1 = (int) $a->antet->FacturaID;
                $int_value2 = (int) $b->antet->FacturaID;
                return $int_value1 < $int_value2 ? -1 : 1;
            });

            foreach ($rt->facturi as $factura) {
                foreach ($factura->detalii->Continut->linii as $linie) {
                    $counter = $bag->invoiceLog->getCounter($linie->Descriere);
                    $counter->count++;
                    $counter->sum += $linie->Pret + $linie->TVA;
                }
            }

            return $bag->invoiceLog;
        }
        finally {
            $this->em->getFilters()->enable('softdeleteable');
        }
    }

    function log(String $message) {

    }

    function processByInvoiceDate(EntityManagerInterface $em, ProcessBag $bag) {
        $this->log("Processing by invoice date");

        $startDate = date('Y-m-d', strtotime($bag->startDate));
        $endDate = date('Y-m-d', strtotime($bag->endDate));

        $dql = "SELECT rp FROM AppBundle:RentPayment rp WHERE (rp.invoiceDate>=:startDate and rp.invoiceDate<=:endDate) order by rp.invoiceDate asc";
        $firstResult = 0;
        $pagination = 50;
        $finished = false;

        while (!$finished) {
            /** @var RentPayment[] $payments */
            $payments = $em->createQuery($dql)
                ->setParameters([
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ])
                ->setFirstResult($firstResult)
                ->setMaxResults($pagination)
                ->getResult();

            if (!$payments) {
                break;
            }

            $pageEnd = $firstResult + $pagination;
            $this->log("Processing $firstResult - $pageEnd. Payments: " . count($payments));

            foreach ($payments as $payment) {
                $bag->processPayment($em, $payment);
            }

            $firstResult += $pagination;
            $payments = null;
        }
    }

    function processByPenaltyInvoiceDate(EntityManagerInterface $em, ProcessBag $bag) {
        $this->log("Processing by penalty invoice date");

        $startDate = date('Y-m-d', strtotime($bag->startDate));
        $endDate = date('Y-m-d', strtotime($bag->endDate));

        $dql = "SELECT rp FROM AppBundle:RentPayment rp WHERE (rp.penaltyInvoiceDate>=:startDate and rp.penaltyInvoiceDate<=:endDate) order by rp.penaltyInvoiceDate ASC";
        $firstResult = 0;
        $pagination = 50;
        $finished = false;

        $data = [];
        while (!$finished) {
            /** @var RentPayment[] $payments */
            $payments = $em->createQuery($dql)
                ->setParameters([
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ])
                ->setFirstResult($firstResult)
                ->setMaxResults($pagination)
                ->getResult();

            if (!$payments) {
                break;
            }

            $pageEnd = $firstResult + $pagination;
            $this->log("Processing $firstResult - $pageEnd. Payments: " . count($payments));

            foreach ($payments as $payment) {
                $bag->processPaymentPenalty($em, $payment);
            }

            $firstResult += $pagination;
            $payments = null;
        }

        return $data;
    }
}

class InvoiceLog {
    public $facturi = 0;
    public $chirie = 0;
    public $utilitati = 0;
    public $refacturare = 0;
    public $transaction_fee = 0;
    public $tenant_fee = 0;
    public $tenant_setup_fee = 0;
    public $lister_fee = 0;
    public $lister_setup_fee = 0;
    public $penalty = 0;
    public $noTenants = 0;

    public $byInvoiceDate = 0;
    public $byPenaltyInvoiceDate = 0;

    /** @var RentPayment[] $allPayments */
    public $allPayments = [];

    /** @var InvoiceTypeCounter[] $counters */
    public $counters;

    public function getCounter(string $type) {
        foreach ($this->counters as $counter) {
            if ($counter->type === $type) {
                return $counter;
            }
        }

        $counter = new InvoiceTypeCounter();
        $counter->type = $type;
        $counter->count = 0;
        $counter->sum = 0;
        $this->counters []= $counter;

        return $counter;
    }
}

class InvoiceTypeCounter {
    public $type;
    public $count;
    public $sum;
}

class ProcessBag {
    /** @var string $startDate */
    public $startDate;

    /** @var string $endDate */
    public $endDate;

    /** @var InvoiceLog $invoiceLog */
    public $invoiceLog;

    public function processPayment(EntityManagerInterface $em, RentPayment $payment) {

    }

    public function processPaymentPenalty(EntityManagerInterface $em, RentPayment $payment) {

    }
}

class ExportZipfilesProcessBag extends ProcessBag {
    /** @var BillInfo[] $bills */
    public $bills;

    /** @var ZipStreamer $zipstreamer */
    public $zipstreamer;

    public function processPayment(EntityManagerInterface $em, RentPayment $payment) {
        if ($payment->getFilenameBill()) {
            $bill = new BillInfo();
            $bill->paymentId = $payment->getId();
            $bill->uri = AmazonS3Service::URL_PREFIX.'bills/'.$payment->getFilenameBill();

            switch ($payment->getType()) {
                case RentPayment::TYPE_RENT:
                    $bill->invoiceType = "chirie";
                    break;
                case RentPayment::TYPE_UTILITY:
                    $bill->invoiceType = "utilitati";
                    break;
                case RentPayment::TYPE_REFACTURARE:
                    $bill->invoiceType = "refacturare";
                    break;
                case RentPayment::TYPE_ADVANCE_RENT_OFFER:
                    $bill->invoiceType = "ADVANCE_RENT_OFFER";
                    break;
                default:
                    break;
            }

            $this->addBill($bill);
        }

        if ($payment->getTenantFeeBill()) {
            $bill = new BillInfo();
            $bill->paymentId = $payment->getId();
            $bill->uri = AmazonS3Service::URL_PREFIX.'bills/'.$payment->getTenantFeeBill();
            $bill->invoiceType = "tenant_fee";
            $this->addBill($bill);
        }

        if ($payment->getListerFeeBill()) {
            $bill = new BillInfo();
            $bill->paymentId = $payment->getId();
            $bill->uri = AmazonS3Service::URL_PREFIX.'bills/'.$payment->getListerFeeBill();
            $bill->invoiceType = "lister_fee";
            $this->addBill($bill);
        }
    }

    public function processPaymentPenalty(EntityManagerInterface $em, RentPayment $payment) {
        if ($payment->getPenaltyBill()) {
            $bill = new BillInfo();
            $bill->paymentId = $payment->getId();
            $bill->uri = AmazonS3Service::URL_PREFIX.'bills/'.$payment->getPenaltyBill();
            $bill->invoiceType = "penalty";
            $this->addBill($bill);
        }
    }

    function addBill(BillInfo $bill) {
        // check that the bill wasn't added yet
        $existing = current(array_filter($this->bills, function($x) use($bill) {
            return $x->uri == $bill->uri;
        }));
        if (!$existing) {
            $this->bills []= $bill;
        }
    }
}

class BillInfo {
    public $paymentId;
    public $uri;
    public $filename;
    public $invoiceType;
    public $error;
}

class ExportXmlInfoProcessBag extends ProcessBag {
    /** @var Factura[] $facturi */
    public $facturi = [];

    public function processPayment(EntityManagerInterface $em, RentPayment $payment) {
        $rentOffer = $payment->getRentOffer();
        $client = $rentOffer->getUser();
        $listing = $rentOffer->getListing();
        $owner = $listing->getUser();
        $lister = $listing->getLister();
        if ($lister) $owner = $lister;

        $this->invoiceLog->allPayments [] = $payment;

        $isManagerTenant = \AppBundle\Utils\CustomObjects\RentOffer::isManager($client);
        if ($isManagerTenant) {
            /** @var Tenant|null $tenant */
            $tenant = $em->getRepository('AppBundle:Tenant')->findOneBy(['rentOffer' => $rentOffer, 'isLister' => true]);
        } else {
            /** @var Tenant|null $tenant */
            $tenant = $em->getRepository('AppBundle:Tenant')->findOneBy(['rentOffer' => $rentOffer, 'isLister' => false]);
        }
        if (!$tenant) {
            $noTenantPayments [] = $payment;
            $this->invoiceLog->noTenants++;
            return;
        }

        if ($payment->getFilenameBill()) {
            $factura = $this->getBasicInvoiceNew($owner, $client, $tenant, $listing, $payment);
            $this->facturi []= $factura;
        }

        if ($payment->getTenantFee() > 0 and $payment->getTenantFeeBill()) {
            $factura = $this->getTenantInvoiceNew($owner, $client, $tenant, $listing, $payment);
            $this->facturi []= $factura;
        }

        if ($payment->getListerFee() > 0 and $payment->getListerFeeBill()) {
            $factura = $this->getListerInvoiceNew($owner, $client, $tenant, $listing, $payment);
            $this->facturi []= $factura;
        }
    }

    function processPaymentPenalty(EntityManagerInterface $em, RentPayment $payment) {
        $rentOffer = $payment->getRentOffer();
        $client = $rentOffer->getUser();
        $listing = $rentOffer->getListing();
        $owner = $listing->getUser();
        $lister = $listing->getLister();
        if ($lister) $owner = $lister;

        $this->invoiceLog->allPayments [] = $payment;

        $isManagerTenant = \AppBundle\Utils\CustomObjects\RentOffer::isManager($client);
        if ($isManagerTenant) {
            /** @var Tenant|null $tenant */
            $tenant = $em->getRepository('AppBundle:Tenant')->findOneBy(['rentOffer' => $rentOffer, 'isLister' => true]);
        } else {
            /** @var Tenant|null $tenant */
            $tenant = $em->getRepository('AppBundle:Tenant')->findOneBy(['rentOffer' => $rentOffer, 'isLister' => false]);
        }
        if (!$tenant) {
            $noTenantPayments [] = $payment;
            $this->invoiceLog->noTenants++;
            return;
        }

        if ($payment->getPenalty() and $payment->getPenaltyBill()) {
            $factura = $this->getPenaltyInvoiceNew($owner, $client, $tenant, $listing, $payment);
            $this->facturi []= $factura;
        }
    }

    function getPenaltyInvoiceNew($owner, $client, $tenant, $listing, RentPayment $payment)
    {
        $factura = new Factura();
        $factura->detalii = new Detalii();
        $factura->detalii->Continut = new Continut();
        $factura->detalii->Continut->linii = [];

        $factura->antet = $this->generateAntetNEW($owner, $client, $tenant, $listing, $this->getInvoiceNumber($payment->getPenaltyBill()), $payment, true);

        $exchangeRate = $payment->getExchangeRate();
        if (!$exchangeRate) {
            // the penalty currency is in the same bill currency, so
            // when the exchange rate is null it means the utilities bill refers to a bill in RON so the penalty is in RON as well
            $exchangeRate = 1;
        }
        $amountPenalty = $payment->getPenalty() * $exchangeRate;
        $amountPenalty = (double)number_format($amountPenalty, 2, '.', '');

        $lineNo = 1;
        $cont = $this->getCont(false, $payment, true, false);
        $factura->detalii->Continut->linii [] = $this->getLineNEW($lineNo++, 'penalty', $amountPenalty, $amountPenalty, 0, 0, $cont);
        $this->invoiceLog->penalty++;

        return $factura;
    }

    function getListerInvoiceNew($owner, $client, $tenant, $listing, RentPayment $payment): Factura
    {
        $factura = new Factura();
        $factura->detalii = new Detalii();
        $factura->detalii->Continut = new Continut();
        $factura->detalii->Continut->linii = [];

        $factura->antet = $this->generateAntetNEW($owner, $client, $tenant, $listing, $this->getInvoiceNumber($payment->getListerFeeBill()), $payment, false);

        $amountListerFee = $payment->getListerFee() / 1.19 * $payment->getExchangeRate();
        $amountListerFee = (double)number_format($amountListerFee, 2, '.', '');

        $tva = $this->getTva($amountListerFee);
        $lineNo = 1;

        $cont = $this->getCont(true, $payment, false, false);
        $factura->detalii->Continut->linii [] = $this->getLineNew($lineNo++, 'lister fee', $amountListerFee, $amountListerFee, 19, $tva, $cont);
        $this->invoiceLog->lister_fee++;

        if ($payment->getListerSetupFee() > 0) {
            $amountListerFee = $payment->getListerSetupFee() / 1.19 * $payment->getExchangeRate();
            $amountListerFee = (double)number_format($amountListerFee, 2, '.', '');

            $tva = $this->getTva($amountListerFee);
            $cont = $this->getCont(true, $payment, false, false);
            $factura->detalii->Continut->linii  [] = $this->getLineNew($lineNo++, 'lister setup fee', $amountListerFee, $amountListerFee, 19, $tva, $cont);
            $this->invoiceLog->lister_setup_fee++;
        }

        return $factura;
    }

    function getTenantInvoiceNEW($owner, $client, $tenant, $listing, $payment): Factura
    {
        $factura = new Factura();
        $factura->detalii = new Detalii();
        $factura->detalii->Continut = new Continut();
        $factura->detalii->Continut->linii = [];

        $factura->antet = $this->generateAntetNEW($owner, $client, $tenant, $listing, $this->getInvoiceNumber($payment->getTenantFeeBill()), $payment, false);

        $amountTenantFee = $payment->getTenantFee() / 1.19 * $payment->getExchangeRate();
        $amountTenantFee = (double)number_format($amountTenantFee, 2, '.', '');

        $tva = $amountTenantFee * 0.19;
        $tvaTenantFee = (double)number_format($tva, 2, '.', '');

        $lineNo = 1;

        $cont = $this->getCont(true, $payment, false, false);
        $factura->detalii->Continut->linii []= $this->getLineNEW($lineNo++, 'tenant fee', $amountTenantFee, $amountTenantFee, 19, $tvaTenantFee, $cont);

        $this->invoiceLog->tenant_fee++;

        if ($payment->getTenantSetupFee() > 0) {
            $amountTenantFee = $payment->getTenantSetupFee() / 1.19 * $payment->getExchangeRate();
            $amountTenantFee = (double)number_format($amountTenantFee, 2, '.', '');

            $tvaTenantFee = $this->getTva($amountTenantFee);
            $cont = $this->getCont(true, $payment, false, false);
            $factura->detalii->Continut->linii []= $this->getLineNEW($lineNo++, 'tenant setup fee', $amountTenantFee, $amountTenantFee, 19, $tvaTenantFee, $cont);

            $this->invoiceLog->tenant_setup_fee++;
        }

        return $factura;
    }

    function getBasicInvoiceNew($owner, $client, $tenant, $listing, RentPayment $payment): Factura
    {
        $factura = new Factura();
        $factura->detalii = new Detalii();
        $factura->detalii->Continut = new Continut();
        $factura->detalii->Continut->linii = [];

        $factura->antet = $this->generateAntetNEW($owner, $client, $tenant, $listing, $this->getInvoiceNumber($payment->getFilenameBill()), $payment, false);

        $this->invoiceLog->facturi++;

        $descriere = '';
        $amount = 0;
        $isDeposit = false;
        $cont = null;
        switch ($payment->getType()) {
            case RentPayment::TYPE_RENT:
            {
                $descriere = 'chirie';
                $amount = $payment->getAmount() * $payment->getExchangeRate();
                $this->invoiceLog->chirie++;
                break;
            }
            case RentPayment::TYPE_UTILITY:
            {
                $descriere = 'utilitati';
                $amount = $payment->getAmount();

                if ($payment->getPenalty() > 0) {
                    $curs = $payment->getExchangeRate();
                    if (!$curs)
                        $curs = 1;
                    $penalty = $payment->getPenalty() * $curs;
                    $amount = $amount - $penalty;
                }

                $this->invoiceLog->utilitati++;
                break;
            }
            case RentPayment::TYPE_REFACTURARE:
            {
                $descriere = 'refacturare';

                $isStorno = $payment->getAmount() < 0;
                if ($isStorno) {
                    if ($this->contains($payment->getInfo(), 'chirie')) {
                        $descriere = 'chirie';
                        $cont = '462.02';
                    }
                    if ($this->contains($payment->getInfo(), 'garantie')) {
                        $descriere = 'garantie';
                        $cont = '167';
                    }
                    if ($this->contains($payment->getInfo(), 'utilitati')) {
                        $descriere = 'utilitati';
                        $cont = '462.02';
                    }
                    if ($this->contains($payment->getInfo(), 'fee')) {
                        $descriere = 'fee';
                        $cont = '704';
                    }
                }
                else {
                    if ($this->contains($payment->getInfo(), 'garantie')) {
                        $isDeposit = true;
                        $descriere = 'garantie';
                    }
                }

                $amount = $payment->getAmount();

                if ($payment->getPenalty() > 0) {
                    $curs = $payment->getExchangeRate();
                    if (!$curs)
                        $curs = 1;
                    $penalty = $payment->getPenalty() * $curs;
                    $amount = $amount - $penalty;
                }

                $this->invoiceLog->refacturare++;
                break;
            }
        }
        $amount = (double)number_format($amount, 2, '.', '');

        $lineNo = 1;
        $curs = $payment->getExchangeRate();

        if ($isStorno) {
            if (!$cont) {
                throw new \Exception('Storno payment (negative amount) with no valid info: '.$payment->getId(), ExceptionListener::INVALID_DATA);
            }
            $linie = $this->getLineNEW($lineNo++, $descriere, $amount, $amount, 0, 0, $cont);
            $factura->detalii->Continut->linii [] = $linie;
        }
        else {
            if ($this->isFirstBill($payment)) {
                $rentPriceRON = ($payment->getAmount() * $curs
                        - $payment->getPenalty() * $curs
                        - $payment->getTenantFee() * $curs)
                    / ($payment->getRentOffer()->getDeposit() + 1);

                // add first rent line
                $cont = $this->getCont(false, $payment, false, false);
                $linie = $this->getLineNEW($lineNo++, $descriere, $rentPriceRON, $rentPriceRON, 0, 0, $cont);
                $factura->detalii->Continut->linii [] = $linie;

                // add deposit
                if ($payment->getRentOffer()->getDeposit() > 0) {
                    $descriere = 'garantie';
                    $deposit = $payment->getRentOffer()->getDeposit() * $rentPriceRON;
                    $cont = $this->getCont(false, $payment, false, true);
                    $linie = $this->getLineNEW($lineNo++, $descriere, $deposit, $deposit, 0, 0, $cont);
                    $factura->detalii->Continut->linii [] = $linie;
                }
            } else {
                if ($payment->getType() == RentPayment::TYPE_RENT) {
                    $amount = $payment->getAmount() * $curs
                        - $payment->getPenalty() * $curs
                        - $payment->getTenantFee() * $curs;
                }

                $cont = $this->getCont(false, $payment, false, $isDeposit);
                $linie = $this->getLineNEW($lineNo++, $descriere, $amount, $amount, 0, 0, $cont);
                $factura->detalii->Continut->linii [] = $linie;
            }
        }

        if ($payment->getTransactionFee() > 0) {
            throw new \Exception('Payment with transaction fee found: '.$payment->getId(), ExceptionListener::LISTING_NOT_FOUND);
        }

        return $factura;
    }

    function getCont(bool $isFee, RentPayment $rentPayment, bool $isPenalty, $isDeposit): string {
        if ($isFee)
            return "704";

        if ($isPenalty)
            return "7581";

        if ($isDeposit)
            return "167";

        if ($this->contains($rentPayment->getInfo(), 'transa')) {
            return '167';
        }
//        $info = $rentPayment->getInfo();
//        if ($info && strpos(strtolower($info), 'transa') > -1) {
//            return "167";
//        }

        return "462.02";
    }

    function contains(String $haystack, String $needle) :bool {
        return $haystack && stripos($haystack, $needle) !== false;
    }

    function getTva($price)
    {
        $tva = $price * 0.19;
        $tvaListerFee = (double)number_format($tva, 2, '.', '');
        return $tvaListerFee;
    }

    function getLineNEW($lineNo, $descriere, $pret, $valoare, $cotaTva, $tva, $cont)
    {
        $linie = new Linie();
        $linie->LinieNrCrt = $lineNo;
        $linie->Gestiune = 'sediu';
        $linie->Activitate = '';
        $linie->Descriere = $descriere;
        $linie->CodArticolFurnizor = '';
        $linie->CodArticolClient = '';
        $linie->CodBare = '';
        $linie->InformatiiSuplimentare = '';
        $linie->UM = 'BUC';
        $linie->Cantitate = 1;
        $linie->Pret = $pret;
        $linie->Valoare = $valoare;
        $linie->ProcTVA = $cotaTva;
        $linie->TVA = $tva;
        $linie->Cont = $cont;

        return $linie;
    }

    function getInvoiceNumber(string $filename)
    {
        $vct = explode('-', $filename);

        return $vct[0];
    }

    function generateAntetNEW(User $owner, User $client, Tenant $tenant, Listing $listing, $invoiceNumber, RentPayment $payment, bool $isPenaltyInvoice): Antet
    {
        $invoiceDate = $isPenaltyInvoice ? $payment->getPenaltyInvoiceDate() : $payment->getInvoiceDate();

        $antet = new Antet();

        $antet->FurnizorNume = 'PROPTECH CORP SRL';
        $antet->FurnizorCIF = 'RO41276829';
        $antet->FurnizorNrRegCom = 'J28/788/2019';
        $antet->FurnizorCapital = '45000';
        $antet->FurnizorTara = 'ROMANIA';
        $antet->FurnizorJudet = 'OLT';
        $antet->FurnizorAdresa = 'STR.PITESTI NR 56 CAM 1';
        $antet->FurnizorBanca = 'Banca Transilvania';
        $antet->FurnizorIBAN = 'RO22BTRLRONCRT0507097801';
        $antet->FurnizorInformatiiSuplimentare = 'Emis in numele ' . ($owner->getFirstName() . ' ' . $owner->getLastName());
        $antet->ClientNume = $tenant->getFirstName() . ' ' . $tenant->getLastName();
        $antet->ClientInformatiiSuplimentare = $listing->getAddress();
        $antet->ClientCIF = $tenant->getTaxId() ?? $tenant->getCnp();
        $antet->ClientNrRegCom = $tenant->getTradeRegistration() ?? '';
        $antet->ClientTara = 'ROMANIA';
        $antet->ClientAdresa = '';
        $antet->ClientBanca = '';
        $antet->ClientIBAN = '';
        $antet->ClientTelefon = $client->getPhoneNumber();
        $antet->ClientMail = $client->getEmail();
        $antet->FacturaNumar = $invoiceNumber;
        $antet->FacturaData = $invoiceDate->format('d.m.Y');
        $antet->FacturaScadenta = $payment->getDueDate()->format('d.m.Y');
        $antet->FacturaTaxareInversa = 'NU';
        $antet->FacturaTVAIncasare = 'NU';
        $antet->FacturaTip = '';
        $antet->FacturaInformatiiSuplimentare = '';
        $antet->FacturaMoneda = 'RON';
        $antet->FacturaID = $invoiceNumber;

        return $antet;
    }

    private function isFirstBill(RentPayment $payment) {
        return $payment
                && $payment->getFilenameBill()
                && strpos($payment->getFilenameBill(), "first-bill") > -1;
    }
}

/**
 * @XmlRoot ("Facturi")
 */
class Facturi {
    /** @var Factura[] $facturi
     * @Serializer\XmlList(inline=true, entry="Factura")
     */
    public $facturi;

}

class Factura {
    /** @var Antet $antet
     * @SerializedName("Antet")
     *
     */
    public $antet;

    /** @var Detalii $detalii
     * @SerializedName("Detalii")
     * @XmlElement(cdata=false)     *
     */
    public $detalii;
}

class Antet {
    /**
     * @SerializedName ("FurnizorNume")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorNume;
    /**
     * @SerializedName ("FurnizorCIF")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorCIF;
    /**
     * @SerializedName ("FurnizorNrRegCom")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorNrRegCom;
    /**
     * @SerializedName ("FurnizorCapital")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorCapital;
    /**
     * @SerializedName ("FurnizorTara")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorTara;
    /**
     * @SerializedName ("FurnizorJudet")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorJudet;
    /**
     * @SerializedName ("FurnizorAdresa")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorAdresa;
    /**
     * @SerializedName ("FurnizorBanca")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorBanca;
    /**
     * @SerializedName ("FurnizorIBAN")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorIBAN;
    /**
     * @SerializedName ("FurnizorInformatiiSuplimentare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FurnizorInformatiiSuplimentare;
    /**
     * @SerializedName ("ClientNume")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientNume;
    /**
     * @SerializedName ("ClientInformatiiSuplimentare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientInformatiiSuplimentare;
    /**
     * @SerializedName ("ClientCIF")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientCIF;
    /**
     * @SerializedName ("ClientNrRegCom")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientNrRegCom;
    /**
     * @SerializedName ("ClientTara")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientTara;
    /**
     * @SerializedName ("ClientAdresa")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientAdresa;
    /**
     * @SerializedName ("ClientBanca")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientBanca;
    /**
     * @SerializedName ("ClientIBAN")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientIBAN;
    /**
     * @SerializedName ("ClientTelefon")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientTelefon;
    /**
     * @SerializedName ("ClientMail")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ClientMail;
    /**
     * @SerializedName ("FacturaNumar")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaNumar;
    /**
     * @SerializedName ("FacturaData")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaData;
    /**
     * @SerializedName ("FacturaScadenta")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaScadenta;
    /**
     * @SerializedName ("FacturaTaxareInversa")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaTaxareInversa;
    /**
     * @SerializedName ("FacturaTVAIncasare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaTVAIncasare;
    /**
     * @SerializedName ("FacturaTip")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaTip;
    /**
     * @SerializedName ("FacturaInformatiiSuplimentare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaInformatiiSuplimentare;
    /**
     * @SerializedName ("FacturaMoneda")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaMoneda;
    /**
     * @SerializedName ("FacturaID")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $FacturaID;
}

class Detalii {
    /** @var Continut $Continut
     * @SerializedName ("Continut")
     */
    public $Continut;
}

class Continut {
    /** @var Linie[] $linii
     * @Serializer\XmlList(inline=true, entry="Linie")
     */
    public $linii;
}

class Linie {
    /**
     * @SerializedName ("LinieNrCrt")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $LinieNrCrt;
    /**
     * @SerializedName ("Gestiune")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Gestiune;
    /**
     * @SerializedName ("Activitate")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Activitate;
    /**
     * @SerializedName ("Descriere")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Descriere;
    /**
     * @SerializedName ("CodArticolFurnizor")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $CodArticolFurnizor;
    /**
     * @SerializedName ("CodArticolClient")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $CodArticolClient;
    /**
     * @SerializedName ("CodBare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $CodBare;
    /**
     * @SerializedName ("InformatiiSuplimentare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $InformatiiSuplimentare;
    /**
     * @SerializedName ("UM")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $UM;
    /**
     * @SerializedName ("Cantitate")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Cantitate;
    /**
     * @SerializedName ("Pret")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Pret;
    /**
     * @SerializedName ("Valoare")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Valoare;
    /**
     * @SerializedName ("ProcTVA")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $ProcTVA;
    /**
     * @SerializedName ("TVA")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $TVA;
    /**
     * @SerializedName ("Cont")
     * @Type("string")
     * @XmlElement(cdata=false)
     */
    public $Cont;
}



