<?php

  namespace App\Http\Livewire;

//  use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;


  class Categories extends Component
  {
    public $opn = 'x';
    public $opns = 'x';
    public $poz ;
    public $active ;
    public $deleteId = '';
    public $search = '' ;
    public $ssearch = false;
    public $edit ;
    public $editok ;
    public $add = false ;
    public $idc = '' ;
    public $lvl = '' ;

    public $widgetId;
    public $shortId;
    public $origName;
    public $newName;
    public $isName;
    public $name;


    public function mount(\App\Models\Admin\Categories $widget)
      {

        $this->widgetId = $widget->id;
        $this->origName = $widget->name;
        $this->init($widget);
      }
    public function render()
      {
        $categorii = Cache::rememberForever('categorii',  function () {
        $langcurrent = Cache::get('lang');
        return Categories::orderBy('poz','asc')
          ->where('id_lang',$langcurrent->id)
          ->where('lvl','1')
          ->with(['subcategorii' => function ($q)  {
            $langcurrent = Cache::get('lang');
            $q->orderBy('poz', 'asc')
              ->where('lvl', '2')
              ->where('id_lang', $langcurrent->id)
              ->with(['subsubcategorii' => function ($qq)  {
                $langcurrent = Cache::get('lang');
                $qq->orderBy('poz', 'asc')
                  ->where('lvl', '3')
                  ->where('id_lang', $langcurrent->id)
                ; }])
            ; }])
          ->get();
      });

        return view('livewire.categories', ['categorii' =>$categorii]);
      }
    public function open($id)
      {
        ($this->opn === 'x')?$this->opn = $id:$this->opn = 'x';
      }
    public function opens($id)
      {
        ($this->opns === 'x')?$this->opns = $id:$this->opns = 'x';
      }

    public function update($id)
      {
        $record = \App\Models\Admin\Categories::findOrFail($id);
        $record->update([
         'poz' => $this->poz
          ]);
        $this->dispatchBrowserEvent('alert',
         [
          'type' => 'success',
          'message' => 'Modificarea a fost efectuata'
        ]);
      }

    public function active($id)
      {
        $record = \App\Models\Admin\Categories::findOrFail($id);
        if($record->active == 'yes') $activ = 'no'; else $activ = 'yes';
        $record->update([
                          'active' => $activ
                        ]);
        $this->dispatchBrowserEvent('alert', [
          'type' => 'success',
          'message' => 'Modificarea a fost efectuata'
        ]);
      }
    /**
     * Write code on Method
     *
     * @return response()
     */
    public function deleteId($id)
      {
        $this->deleteId = $id;
      }
    /**
     * Write code on Method
     *
     * @return response()
     */
    public function delete()
      {
        \App\Models\Admin\Categories::find($this->deleteId)->delete();
      }
    public function save()
      {
        $widget = \App\Models\Admin\Categories::findOrFail($this->edit);
//      $newName = (string)Str::of($this->newName)->trim()->substr(0, 100);
        $newName =  $this->newName;

        $widget->name = $newName ?? null;
        $widget->save();
        $this->editok = $this->edit;
        $this->init($widget);
      }

    private function init(\App\Models\Admin\Categories $widget)
      {
        $this->origName = $widget->name;
        $this->newName = $this->origName;
        $this->isName = $widget->name ?? false;
      }

    public function edit($id)
      {
        $this->edit = $id;
      }
    public function showInsert($idc,$lvl)
      {
        $this->idc = $idc;
        $this->lvl = $lvl;
        $this->add = !$this->add;
      }

    /**
     * @return void
     */
    public function store(): void
      {

        $data = array(
          'name' => $this->name,
          'idc' => $this->idc,
          'active' => $this->active,
          'poz' => $this->poz,
          'lvl' => $this->lvl,
          'url' => 'generare',
          'img' => 'imagine',
          'filtre' => '1',
          'id_lang' => '1'
        );
        \App\Models\Admin\Categories::create($data);
        $this->add = false;
        $this->dispatchBrowserEvent('alert', [
          'type' => 'success',
          'message' => 'Categoria a fost adaugata!'
        ]);
      }
  }