{if isset($tsxmlinvoice_url)}
    <a class="btn btn-default" href="{$tsxmlinvoice_url|escape:'htmlall':'UTF-8'}" target="{$tsxmlinvoice_target|default:'_blank'|escape:'htmlall':'UTF-8'}">
        <i class="material-icons">code</i>
        {l s='View XML Invoice' mod='tsxmlinvoice'}
    </a>
{/if}
