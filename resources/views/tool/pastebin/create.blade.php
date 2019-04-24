@extends('layouts.app')

@section('template')
<style>
    h1{
        font-family: Raleway;
        font-weight: 100;
        text-align: center;
    }
    #vscode_container_outline{
        border: 1px solid #ddd;
        /* padding:2px; */
        border-radius: 2px;
        margin-bottom:2rem;
        background: #fff;
        overflow: hidden;
    }
    a.action-menu-item:hover{
        text-decoration: none;
    }
    input.form-control {
        height: calc(2.4375rem + 2px);
    }
</style>
<div class="container mundb-standard-container">
    <h1>Instantly share code, notes, and snippets.</h1>
    <div class="row">
        <div class="col-lg-4 col-12">
            <div class="form-group bmd-form-group is-filled">
                <label for="pb_lang" class="bmd-label-floating">Syntax</label>
                <select class="form-control" id="pb_lang" name="pb_lang" required="">

                </select>
            </div>
        </div>
        <div class="col-lg-4 col-12">
                <div class="form-group bmd-form-group is-filled">
                    <label for="pb_time" class="bmd-label-floating">Expiration</label>
                    <select class="form-control" id="pb_time" name="pb_time" required="">
                        <option value="0">None</option>
                        <option value="1">A Day</option>
                        <option value="7">A Week</option>
                        <option value="30">A Month</option>
                    </select>
                </div>
            </div>
        <div class="col-lg-4 col-12">
            <div class="form-group bmd-form-group is-filled">
                <label for="pb_author" class="bmd-label-floating">Author</label>
                <input type="text" class="form-control" name="pb_author" id="pb_author" value="">
            </div>
        </div>
    </div>
    <div id="vscode_container_outline">
        <div id="vscode_container" style="width:100%;height:50vh;">
            <div id="vscode" style="width:100%;height:100%;"></div>
        </div>
    </div>
    <div style="text-align: right;margin-bottom:2rem;">
        <button type="button" class="btn btn-secondary">Cancel</button>
        <button type="button" class="btn btn-raised btn-primary">Create</button>
    </div>
</div>
@endsection

@section('additionJS')
    <script src="/static/vscode/vs/loader.js"></script>
    <script>
        var aval_lang=[];
        require.config({ paths: { 'vs': '{{env('APP_URL')}}/static/vscode/vs' }});

        // Before loading vs/editor/editor.main, define a global MonacoEnvironment that overwrites
        // the default worker url location (used when creating WebWorkers). The problem here is that
        // HTML5 does not allow cross-domain web workers, so we need to proxy the instantiation of
        // a web worker through a same-domain script

        window.MonacoEnvironment = {
            getWorkerUrl: function(workerId, label) {
                return `data:text/javascript;charset=utf-8,${encodeURIComponent(`
                self.MonacoEnvironment = {
                    baseUrl: '{{env('APP_URL')}}/static/vscode/'
                };
                importScripts('{{env('APP_URL')}}/static/vscode/vs/base/worker/workerMain.js');`
                )}`;
            }
        };

        require(["vs/editor/editor.main"], function () {
            editor = monaco.editor.create(document.getElementById('vscode'), {
                value: "",
                language: "plaintext",
                theme: "vs-light",
                fontSize: 16,
                formatOnPaste: true,
                formatOnType: true,
                automaticLayout: true,
            });
            $("#vscode_container").css("opacity",1);
            var all_lang=monaco.languages.getLanguages();
            all_lang.forEach(function (lang_conf) {
                aval_lang.push(lang_conf.id);
                $("#pb_lang").append("<option value='"+lang_conf.id+"'>"+lang_conf.aliases[0]+"</option>");
                console.log(lang_conf.id);
            });
            $('#pb_lang').change(function(){
                var targ_lang=$(this).children('option:selected').val();
                monaco.editor.setModelLanguage(editor.getModel(), targ_lang);
            });
            // monaco.editor.setModelLanguage(editor.getModel(), "plaintext");
        });
    </script>
@endsection

