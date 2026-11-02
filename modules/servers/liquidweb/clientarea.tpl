{if !$lwSuspended}
{literal}
<style>
.lw-server, .lw-server h3, .lw-server p, .lw-server dt, .lw-server dd {
    text-align: left !important;
}
.lw-server h3.panel-title {
    font-size: 1.125rem;
    line-height: 1.4;
    margin: 0;
}
.lw-server dl {
    display: grid;
    grid-template-columns: minmax(9rem, 1fr) minmax(0, 3fr);
    gap: .6rem 1.25rem;
    margin: 0 0 1.25rem;
}
.lw-server dl dt, .lw-server dl dd {
    float: none;
    width: auto;
    margin: 0;
    padding: 0;
    overflow-wrap: anywhere;
}
.lw-server dl dt { font-weight: 600; }
.lw-server p:last-child { margin-bottom: 0; }
.lw-server .lw-network { margin-top: 1.5rem; }
.lw-server .lw-network h4 { text-align: left; font-size: 1rem; }
.lw-server .lw-network img { display: block; width: 100%; height: auto; margin: .75rem 0; }
@media (max-width: 575px) {
    .lw-server dl { grid-template-columns: minmax(0, 1fr); gap: .25rem; }
    .lw-server dl dd { margin-bottom: .75rem; }
}
</style>
{/literal}
<section class="lw-server panel panel-default card" aria-label="{$lwLang.serverInformation|escape:'html'}">
    <div class="panel-heading card-header"><h3 class="panel-title">{$lwLang.serverInformation|escape:'html'}</h3></div>
    <div class="panel-body card-body">
        {if $lwError}
            <p role="status">{$lwError|escape:'html'}</p>
        {else}
            <dl>
                {foreach $lwRows as $label => $value}
                    <dt>{$label|escape:'html'}</dt>
                    <dd>{$value|escape:'html'}</dd>
                {/foreach}
                <dt>{$lwLang.powerStatus|escape:'html'}</dt>
                <dd>{$lwPower|escape:'html'}</dd>
            </dl>
            <p class="text-muted"><small>{$lwLang.lastChecked|escape:'html'}: {$lwUpdated|escape:'html'}. {$lwLang.powerCached|escape:'html'}</small></p>
            <section id="lw-network" class="lw-network" aria-label="{$lwLang.networkTraffic|escape:'html'}">
                <h4>{$lwLang.networkTraffic|escape:'html'}</h4>
                {if $lwNetwork}
                    <p><small>{$lwNetwork.period|escape:'html'}</small></p>
                    <img src="{$lwNetwork.image|escape:'html'}" alt="{$lwLang.networkAlt|escape:'html'}" width="900" height="300">
                    <p class="text-muted"><small>{$lwNetwork.count|escape:'html'} {$lwLang.samples|escape:'html'}. {$lwLang.networkExplanation|escape:'html'} {$lwLang.lastChecked|escape:'html'}: {$lwNetwork.updated|escape:'html'}.</small></p>
                {elseif $lwNetworkError}
                    <p role="status">{$lwNetworkError|escape:'html'}</p>
                {else}
                    <a class="btn btn-default" href="{$lwNetworkUrl|escape:'html'}">{$lwLang.viewNetwork|escape:'html'}</a>
                {/if}
            </section>
        {/if}
    </div>
</section>

{/if}
