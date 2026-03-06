<a class="btn btn-primary" href="clientarea.php?action=productdetails&id={$serviceid}">Назад</a>
<h2>IP-адреса</h2>
{if $allow_change_ptr}
<table class="table table-list">
	<thead>
		<tr>
			<th style="text-align: left;" width="50">ID</th>
			<th style="text-align: left;">IP</th>
			<th style="text-align: left;">Маска</th>
			<th style="text-align: left;">Шлюз</th>
			<th style="text-align: left;">Домен</th>
			<th style="text-align: left;"></th>
		</tr>
	</thead>
	<tbody>
		{foreach from=$ip_list item=ip}
		<tr>
			<td>{$ip->id}</td>
			<td>{$ip->ip_addr}</td>
			<td>{$ip->mask}</td>
			<td>{$ip->gateway}</td>
			<td>{$ip->domain}</td>
			<td><a href="?action=productdetails&id={$serviceid}&ip_change=1&ip_id={$ip->id}"><i class="fas fa-edit"></i></a></td>
		</tr>
		{/foreach}
	</tbody>
</table>
{/if}
