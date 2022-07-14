<?php
namespace AppBundle\Controller\Angular;

use AppBundle\Entity\RentOffer;
use AppBundle\Entity\RentPayment;
use AppBundle\Entity\Tenant;
use AppBundle\Entity\User;
use AppBundle\Entity\UtilityBill;
use AppBundle\Entity\UtilityProvider;
use AppBundle\EventListener\ExceptionListener;
use AppBundle\Services\AmazonS3Service;
use AppBundle\Services\BillService;
use AppBundle\Services\ExportService;
use AppBundle\Services\PushNotificationService;
use AppBundle\Services\SettingsService;
use AppBundle\Utils\Angular\Exceptions;
use Carbon\Carbon;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use JMS\Serializer\SerializerInterface;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Wamania\ZipStreamedResponseBundle\Response\ZipStreamedResponse;
use Wamania\ZipStreamedResponseBundle\Response\ZipStreamer\ZipStreamer;

class BillController extends FOSRestController
{    
    /**
     * @Rest\Get("/api/angular/export/bills.{_format}")
     *
     * @ApiDoc(
     *      resource=true,
     *      section="Angular-Bills",
     *      requirements={
     *          {"name"="_format","dataType"="string","default"="json","requirement"="json|xml"},
     *      },
     *      parameters={
     *          {"name"="start_date","dataType"="string","required"="false", "description"="Beginning creation timestamp", "format"="YYYY-mm-dd"},
     *          {"name"="end_date","dataType"="string","required"="false", "description"="End creation timestamp", "format"="YYYY-mm-dd"},
     *      }
     * )
     *
     * @Security("has_role('ROLE_MODERATOR')")
     */
    public function exportBills(Request $request, EntityManagerInterface $em, BillService $billService, SettingsService $settingsService, ExportService $export)
    {
        // get logger
        $loggerDb = $this->get('monolog.logger.db');

        /**
         * @var User $admin
         */
        $admin = $this->getUser();
        if (!$admin) {
            throw new AccessDeniedHttpException('Access denied!', null, Exceptions::ACCESS_DENIED);
        }

        // get parameters
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $zipStreamer = $export->exportZip($startDate, $endDate);

        return new ZipStreamedResponse($zipStreamer);
    }

    /**
     * @Rest\Get("/api/angular/export/bills_saga.{_format}")
     *
     * @ApiDoc(
     *      resource=true,
     *      section="Angular-Bills",
     *      requirements={
     *          {"name"="_format","dataType"="string","default"="json","requirement"="json|xml"},
     *      },
     *      parameters={
     *          {"name"="start_date","dataType"="string","required"="false", "description"="Beginning creation timestamp", "format"="YYYY-mm-dd"},
     *          {"name"="end_date","dataType"="string","required"="false", "description"="End creation timestamp", "format"="YYYY-mm-dd"},
     *      }
     * )
     *
     * @Security("has_role('ROLE_MODERATOR')")
     */
    public function exportBillsSaga(Request $request, EntityManagerInterface $em, BillService $billService, SettingsService $settingsService, ExportService $export, SerializerInterface $serializer)
    {
        // get logger
        $loggerDb = $this->get('monolog.logger.db');

        /**
         * @var User $admin
         */
        $admin = $this->getUser();
        if (!$admin) {
            throw new AccessDeniedHttpException('Access denied!', null, Exceptions::ACCESS_DENIED);
        }

        // get parameters
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $xml = $export->exportXml($startDate, $endDate);

        $response = new Response($xml);

        /** @var ResponseHeaderBag $headers */
        $headers = $response->headers;

        $now=Carbon::now()->format("dmY");
        $disposition = $headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            "F_multiple_41276829_$now.xml",
            "F_multiple_41276829_$now.xml"
        );

        // Set the content disposition
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');

        $response->headers->set('Content-Type', 'application/xml');
        $response->setStatusCode(200);


        return $response;
    }

    /**
     * @Rest\Get("/api/angular/export/bills_stats.{_format}")
     *
     * @ApiDoc(
     *      resource=true,
     *      section="Angular-Bills",
     *      requirements={
     *          {"name"="_format","dataType"="string","default"="json","requirement"="json|xml"},
     *      },
     *      parameters={
     *          {"name"="start_date","dataType"="string","required"="false", "description"="Beginning creation timestamp", "format"="YYYY-mm-dd"},
     *          {"name"="end_date","dataType"="string","required"="false", "description"="End creation timestamp", "format"="YYYY-mm-dd"},
     *      }
     * )
     *
     * @Security("has_role('ROLE_MODERATOR')")
     */
    public function exportBillsStats(Request $request, EntityManagerInterface $em, BillService $billService, SettingsService $settingsService, ExportService $export, SerializerInterface $serializer)
    {
        // get logger
        $loggerDb = $this->get('monolog.logger.db');

        /**
         * @var User $admin
         */
        $admin = $this->getUser();
        if (!$admin) {
            throw new AccessDeniedHttpException('Access denied!', null, Exceptions::ACCESS_DENIED);
        }

        // get parameters
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $info = $export->exportXmlStats($startDate, $endDate);
        $info->allPayments = [];

        $data = $info;
        $view = $this->view($data, 200);
        return $this->handleView($view);
    }
}