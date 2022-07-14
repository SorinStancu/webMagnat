<?php

namespace App\Http\Controllers\Admin;

use App\Models\Admin\Categories;
use Illuminate\Support\Facades\Cache;

class CategoriesController extends Controller
{
	public function categorii()
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


			return view('admin.categories', ['categorii' =>$categorii]);
		}
}