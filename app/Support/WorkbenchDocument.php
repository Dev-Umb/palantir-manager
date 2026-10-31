<?php

namespace App\Support;

use Illuminate\Http\Request;

class WorkbenchDocument
{
    public static function prepare(string $html, Request $request): string
    {
        if (! $request->boolean('embedded')) {
            return $html;
        }

        $style = '<style>@media screen{.sidebar{display:none!important}.workspace{margin-left:0!important}}</style>';
        $bridge = <<<'HTML'
<script>
(function(){
 const publish=()=>{
  const nav=[...document.querySelectorAll('.nav,.progress-nav')].map(b=>({key:b.dataset.view||b.dataset.kind,active:b.classList.contains('active'),count:b.querySelector('b')?.textContent||''}));
  parent.postMessage({type:'production-workbench-state',ready:document.documentElement.dataset.workbenchReady==='true',error:document.documentElement.dataset.workbenchReady==='error'?document.getElementById('save-status')?.textContent:'',nav,backupStatus:document.getElementById('backup-status')?.textContent||''},location.origin);
 };
 const observer=new MutationObserver(publish);
 for(const node of [document.querySelector('.sidebar'),document.documentElement])if(node)observer.observe(node,{subtree:node!==document.documentElement,childList:true,characterData:true,attributes:true,attributeFilter:node===document.documentElement?['data-workbench-ready']:['class']});
 publish();
})();
</script>
HTML;

        return str_replace(['</head>', '</body>'], [$style.'</head>', $bridge.'</body>'], $html);
    }
}
