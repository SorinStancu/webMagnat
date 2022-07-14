<div>
    <div class="col-sm-2" align="right"  >
        <form>
            <div class="form-group m-0">
                <input wire:model="search" class="form-control" type="text" placeholder="Search.." data-original-title="" title="" data-bs-original-title="">
            </div>
        </form>
    </div>
    <div id="basicScenario" class="jsgrid" style="position: relative; height: auto; width: 100%;">
        <div class="jsgrid-grid-header jsgrid-header-scrollbar">
            <div class="col-md-12">

                <table class='table table-bordered  table-hover ' >

                    <tr class="jsgrid-header-row">

                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="100" >
                            Nr.
                        </th>

                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" >
                            Categorie
                        </th>

                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="150">
                            Nr. sub-categorii
                        </th>

                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="150">
                            Filtre
                        </th>
                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="100">
                            Poz.
                        </th>
                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="100">
                            Activ
                        </th>

                        <th class="jsgrid-header-cell jsgrid-align-center jsgrid-header-sortable" align="center" width="150">
                            Optiuni
                        </th>
                    </tr>
                    </thead>
                    {{ Form::open(array('url' => 'categories')) }}
                    <tr class="jsgrid-insert-row  wow bounceInDown" @if($add === false)  style="display:none" @endif >

                        <td class="jsgrid-cell jsgrid-align-center"></td>
                        <td class="jsgrid-cell jsgrid-align-center">

                            {{ form::text( 'name' , '' ,[ 'class' => 'form-control' , 'placeholder' => 'Denumire', 'wire:model'=>'name']) }}
                            {{ form::hidden('idc', $idc) }}
                            {{ form::hidden('lvl', $lvl) }}

                        </td>

                        <td class="jsgrid-cell jsgrid-align-center " align="center" >

                        </td>

                        <td class="jsgrid-cell jsgrid-align-center" align="center" width="150">

                        </td>
                        <td class="jsgrid-cell jsgrid-align-center" align="center" >

                            <select  class='form-select form-control-primary'  wire:model="poz" >

                                @for ($i = 0; $i < 22;   $i++)
                                    <option value="{{ $i }}">{{ $i }}</option>
                                @endfor
                            </select>
                        </td>
                        <td class="jsgrid-cell jsgrid-align-center" align="center" >
                            <div class="media-body text-end icon-state" style="float: left;">
                                <label class="switch">
                                    <input type="checkbox" name="active" wire:model="active" checked  >
                                    <span class="switch-state">
                                        </span>
                                </label>
                            </div>
                        </td>
                        <td class="jsgrid-cell jsgrid-align-center" align="center" >

                            <button class="jsgrid-button jsgrid-insert-mode-button" type="button" title="Insert" wire:click="store()"></button>
                            <button class="jsgrid-button jsgrid-cancel-edit-button" type="button" title="Cancel" wire:click="showInsert(0,1)"></button>
                        </td>
                    </tr>{{ Form::close() }}
                    @foreach($categorii as $categorie)
                        <tr class="@if($edit == $categorie['id'] and $editok != $categorie['id'])jsgrid-edit-row @elseif($editok == $categorie['id'])jsgrid-insert-row @else jsgrid-row @endif  wow bounceInDown">

                            <td class="jsgrid-cell jsgrid-align-center" >{{ $loop->index+1 }}</td>
                            <td class="jsgrid-cell jsgrid-align-center"  >

                                @if($edit == $categorie['id'])
                                    <div x-data="
        {
             isEditing: true,
             isName: '{{ $categorie['name'] }}',
             focus: function() {
                const textInput = this.$refs.textInput;
                textInput.focus();
                textInput.select();
             }
        }
    " x-cloak  >
                                        <div class="p-2" x-show=!isEditing >
        <span x-bind:class="{ 'font-bold': isName }" x-on:click="isEditing = true; $nextTick(() => focus())"  >
            {{ $categorie['name'] }}</span>
                                        </div>
                                        <div x-show=isEditing class="flex flex-col">
                                            <form class="flex" wire:submit.prevent="save">
                                                <input type="text" class="form-control"   x-ref="textInput"
                                                        wire:model.lazy="newName"
                                                        x-on:keydown.enter="isEditing = false"
                                                        x-on:keydown.escape="isEditing = false"  >
                                                <button type="button" class="jsgrid-button jsgrid-cancel-button" title="Cancel"
                                                        x-on:click="isEditing = false"></button>
                                                <button type="submit" class="jsgrid-button jsgrid-update-button"
                                                        title="Save"  x-on:click="isEditing = false"></button>
                                            </form>
                                            <small class="text-xs">Enter to save, Esc to cancel</small>
                                        </div>
                                    </div>
                                @else
                                    {{ $categorie['name'] }}
                                @endif
                            </td>

                            <td class="jsgrid-cell jsgrid-align-center " align="center" >
                                {{ count($categorie['subcategorii']) }}
                                @if(count($categorie['subcategorii'])>0)
                                    <br>
                                    <a href="javascript:void(0)"  wire:click="open({{$categorie['id']}})"    >
                                        <i class="icofont {{ $opn === $categorie['id'] ? 'icofont-arrow-up' : 'icofont-arrow-down' }}"> </i>
                                        {{ $opn === $categorie['id'] ? 'close' : 'open' }}</a>
                                @endif
                            </td>

                            <td class="jsgrid-cell jsgrid-align-center" align="center" width="150">
                                <i class='icofont icofont-settings'
                                        @if($categorie->filtre ==='')  style="color:#919191;"  @else   style="color:#53bf26;" @endif    >
                                </i>
                            </td>
                            <td class="jsgrid-cell jsgrid-align-center" align="center" >

                                <select  class='form-select form-control-primary'  wire:model="poz"
                                        wire:change="update('{{$categorie->id}}')" >
                                    <option>
                                        {{ $categorie->poz }}
                                    </option>
                                    @for ($i = 0; $i < 20; $i++)
                                        <option value="{{ $i }}"
                                                @if ( $categorie->poz  === $i ) selected="selected"  @endif  >
                                            {{ $i }}
                                        </option>
                                    @endfor
                                </select>
                            </td>
                            <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                <div class="media-body text-end icon-state" style="float: left;">
                                    <label class="switch">
                                        <input type="checkbox" name="active"
                                                wire:click="active('{{$categorie->id}}')"
                                                @if($categorie->active === 'yes') checked="" @endif >
                                        <span class="switch-state
                                        @if($categorie->active === 'yes') bg-success @else  icon-state  @endif ">
                                        </span>
                                    </label>
                                </div>
                            </td>

                            <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                <button class="jsgrid-button jsgrid-insert-mode-button" style="width: 24px; height: 24px;"  wire:click="showInsert(0,1)" ></button>
                                <button class="jsgrid-button jsgrid-edit-button"  wire:click="edit({{$categorie->id}})" ></button>
                                <button wire:click="deleteId({{ $categorie->id }})" data-bs-target="#exampleModal" type="button" data-bs-toggle="modal" data-bs-original-title="" title="Delete" class="jsgrid-button jsgrid-delete-button" >
                            </td>
                        </tr>
                        @if($ssearch===false)
                            @foreach ($categorie->subcategorii as $scategorie )
                                @if($opn === $categorie->id || isset($search))
                                    <tr class="@if($edit == $scategorie->id and $editok != $scategorie->id)jsgrid-edit-row @elseif($editok == $scategorie->id)jsgrid-insert-row @else jsgrid-alt-row @endif  wow FadeIn"  @if($opn === $categorie->id)  @else  style="display: none;"   @endif>

                                        <td class="jsgrid-cell jsgrid-align-center" >{{ $loop->index+1 }}</td>
                                        <td class="jsgrid-cell jsgrid-align-center" align="center">
                                            @if($edit == $scategorie->id)
                                                <div x-data="
        {
             isEditing: true,
             isName: '{{ $scategorie->name }}',
             focus: function() {
                const textInput = this.$refs.textInput;
                textInput.focus();
                textInput.select();
             }
        }
    " x-cloak  >
                                                    <div class="p-2" x-show=!isEditing >
        <span x-bind:class="{ 'font-bold': isName }" x-on:click="isEditing = true; $nextTick(() => focus())"  >
            {{ $scategorie->name }}</span>
                                                    </div>
                                                    <div x-show=isEditing class="flex flex-col">
                                                        <form class="flex" wire:submit.prevent="save">
                                                            <input type="text" class="form-control"   x-ref="textInput"
                                                                    wire:model.lazy="newName"
                                                                    x-on:keydown.enter="isEditing = false"
                                                                    x-on:keydown.escape="isEditing = false"  >
                                                            <button type="button" class="jsgrid-button jsgrid-cancel-button" title="Cancel"
                                                                    x-on:click="isEditing = false"></button>
                                                            <button type="submit" class="jsgrid-button jsgrid-update-button"
                                                                    title="Save"  x-on:click="isEditing = false"></button>
                                                        </form>
                                                        <small class="text-xs">Enter to save, Esc to cancel</small>
                                                    </div>
                                                </div>
                                            @else
                                                {{ $scategorie->name }}
                                            @endif
                                        </td>
                                        <td class="jsgrid-cell jsgrid-align-center" align="center"> {{ count($scategorie->subsubcategorii) }}
                                            @if(count($scategorie->subsubcategorii)>0)
                                                <br>
                                                <a href="javascript:void(0)"  wire:click="opens({{$scategorie->id}})">
                                                    <i class="icofont {{ $opns === $scategorie->id ? 'icofont-arrow-up' : 'icofont-arrow-down' }}"> </i>
                                                    {{ $opns === $scategorie->id ? 'close' : 'open' }}</a>
                                            @endif
                                        </td>

                                        <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                            <i class='icofont icofont-settings'
                                                    @if($scategorie->filtre ==='')  style="color:#919191;"  @else   style="color:#53bf26;" @endif    >
                                            </i>
                                        </td>
                                        <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                            <select name="poz" class='form-select form-control-primary'  >
                                                @for ($i = 0; $i < 20; $i++)
                                                    <option value="{{ $i }}"
                                                            @if ( $scategorie->poz  === $i ) selected="selected"  @endif >
                                                        {{ $i }}
                                                    </option>
                                                @endfor
                                            </select>
                                        </td>
                                        <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                            <div class="media-body text-end icon-state" style="float: left;">
                                                <label class="switch">
                                                    <input type="checkbox" name="active"
                                                            wire:click="active('{{$scategorie->id}}')"
                                                            @if($scategorie->active === 'yes') checked="" @endif >
                                                    <span class="switch-state
                                        @if($scategorie->active === 'yes') bg-success @else  icon-state  @endif ">
                                        </span>
                                                </label>
                                            </div>
                                        </td>

                                        <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                            <button class="jsgrid-button jsgrid-insert-mode-button" style="width: 24px; height: 24px;"  wire:click="showInsert({{ $categorie->id }},2)" ></button>
                                            <button class="jsgrid-button jsgrid-edit-button"  wire:click="edit({{$scategorie->id}})" ></button>
                                            <button wire:click="deleteId({{ $scategorie->id }})" data-bs-target="#exampleModal" type="button" data-bs-toggle="modal" data-bs-original-title="" title="Delete" class="jsgrid-button jsgrid-delete-button" >
                                        </td>

                                    </tr>
                                @endif
                                @foreach ($scategorie->subsubcategorii as $sscategorie )
                                    @if($opns === $scategorie->id)

                                        <tr class="@if($edit == $sscategorie->id and $editok != $sscategorie->id)jsgrid-edit-row @elseif($editok == $sscategorie->id)jsgrid-insert-row @else jsgrid-row @endif  wow FadeIn" @if($opns === $scategorie->id) style="background-color: #eaeaea;" @else  style="display: none;"   @endif>

                                            <td class="jsgrid-cell jsgrid-align-center" >{{ $loop->index+1 }}</td>
                                            <td class="jsgrid-cell jsgrid-align-center" align="center">
                                                @if($edit == $sscategorie->id)
                                                    <div x-data="
        {
             isEditing: true,
             isName: '{{ $sscategorie->name }}',
             focus: function() {
                const textInput = this.$refs.textInput;
                textInput.focus();
                textInput.select();
             }
        }
    " x-cloak  >
                                                        <div class="p-2" x-show=!isEditing >
        <span x-bind:class="{ 'font-bold': isName }" x-on:click="isEditing = true; $nextTick(() => focus())"  >
            {{ $sscategorie->name }}</span>
                                                        </div>
                                                        <div x-show=isEditing class="flex flex-col">
                                                            <form class="flex" wire:submit.prevent="save">
                                                                <input type="text" class="form-control"   x-ref="textInput"
                                                                        wire:model.lazy="newName"
                                                                        x-on:keydown.enter="isEditing = false"
                                                                        x-on:keydown.escape="isEditing = false"  >
                                                                <button type="button" class="jsgrid-button jsgrid-cancel-button" title="Cancel"
                                                                        x-on:click="isEditing = false"></button>
                                                                <button type="submit" class="jsgrid-button jsgrid-update-button"
                                                                        title="Save"  x-on:click="isEditing = false"></button>
                                                            </form>
                                                            <small class="text-xs">Enter to save, Esc to cancel</small>
                                                        </div>
                                                    </div>
                                                @else
                                                    {{ $sscategorie->name }}
                                                @endif
                                            </td>

                                            <td class="jsgrid-cell jsgrid-align-center" align="center"></td>

                                            <td class="jsgrid-cell jsgrid-align-center" align="center" width="100">
                                                <i class='icofont icofont-settings'
                                                        @if($sscategorie->filtre ==='')  style="color:#919191;"  @else   style="color:#53bf26;" @endif    >
                                                </i>
                                            </td>
                                            <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                                <select name="poz" class='form-select form-control-primary'  >
                                                    @for ($i = 0; $i < 20; $i++)
                                                        <option value="{{ $i }}"
                                                                @if ( $sscategorie->poz  === $i ) selected="selected"  @endif >
                                                            {{ $i }}
                                                        </option>
                                                    @endfor

                                                </select>  </td>
                                            <td class="jsgrid-cell jsgrid-align-center" align="center" >
                                                <div class="media-body text-end icon-state" style="float: left;">
                                                    <label class="switch">
                                                        <input type="checkbox" name="active"
                                                                wire:click="active('{{$sscategorie->id}}')"
                                                                @if($sscategorie->active === 'yes') checked="" @endif >
                                                        <span class="switch-state
                                        @if($sscategorie->active === 'yes') bg-success @else  icon-state  @endif ">
                                        </span>
                                                    </label>
                                                </div>
                                            </td>

                                            <td class="jsgrid-cell jsgrid-align-center" align="center" width="100">
                                                <button class="jsgrid-button jsgrid-insert-mode-button" style="width: 24px; height: 24px;"  wire:click="showInsert({{ $scategorie->id }},3)" ></button>
                                                <button class="jsgrid-button jsgrid-edit-button"  wire:click="edit({{$sscategorie->id}})" ></button>
                                                <button wire:click="deleteId({{ $sscategorie->id }})" data-bs-target="#exampleModal" type="button" data-bs-toggle="modal" data-bs-original-title="" title="Delete" class="jsgrid-button jsgrid-delete-button" >

                                            </td>

                                        </tr>
                                    @endif

                                @endforeach
                            @endforeach
                        @endif
                    @endforeach

                </table>


                <div wire:ignore.self class="modal fade" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="exampleModalLabel">Confirmare stergere</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">

                                </button>
                            </div>
                            <div class="modal-body">
                                <p>Sunteti sigur ca doriti stergerea?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary close-btn" data-bs-dismiss="modal">Close</button>
                                <button type="button" wire:click.prevent="delete()" class="btn btn-primary close-modal" data-bs-dismiss="modal">Da, sterg</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>