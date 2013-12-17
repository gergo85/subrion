<!--__b_{$id}-->
{if $header}
	<div id="block_{$name}" class="box {$classname}{if isset($collapsible) && $collapsible} collapsible{/if}{if isset($movable) && $movable} movable{/if}">
		<h4 id="caption_{$name}" class="box-caption">{$title|escape:'html'}
			{if isset($icons)}
				<div class="box-caption-buttons">
					{foreach $icons as $icon}
						<a href="{$icon.url}" {$icon.attributes} id="{$icon.name}_{$name}">{$icon.text}</a>
					{/foreach}
				</div>
			{/if}
		</h4>
		<div id="content_{$name}" class="box-content"{if isset($display) && !$display} style="display: none;"{/if}>
{else}
	<div id="block_{$name}" class="box no-header {$classname}{if isset($movable) && $movable} movable{/if}">
{/if}

<!--__b_c_{$id}-->
{$_block_content_}
<!--__e_c_{$id}-->

{if $header}
		</div>
	</div>
{else}
	</div>
{/if}
<!--__e_{$id}-->