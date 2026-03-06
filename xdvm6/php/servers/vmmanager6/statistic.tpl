<a class="btn btn-primary" href="clientarea.php?action=productdetails&id={$serviceid}">Назад</a>
<h2>Статистика</h2>
{if $show_statistic}
<textarea id="vm_info" style="display:none;">{$vm_info}</textarea>
{foreach from=$statistic key=key item=item}
	<textarea id="{$key}_data" style="display:none;">{$item}</textarea>
	{if $key == 'cpu'}
		<h3>vCPU</h3>
	{elseif $key == 'memory'}
		<h3>RAM</h3>
	{elseif $key == 'storage'}
		<h3>Storage</h3>
	{elseif $key == 'net_rx'}
		<h3>Скорость входящего трафика</h3>
	{elseif $key == 'net_tx'}
		<h3>Скорость исходящего трафика</h3>
	{/if}
	<div id="{$key}_chart" class="chart_div" data-key="{$key}" style="width: 100%; height: 300px;"></div>
{/foreach}

{literal}
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
<script type="text/javascript">
	google.charts.load('current', {'packages':['corechart']});
	google.charts.setOnLoadCallback(drawChart);

	function drawChart() {
		const vm_info = JSON.parse(document.getElementById('vm_info').value);

		document.querySelectorAll('.chart_div').forEach(function(elem) {
			const key = elem.getAttribute('data-key');
			const chart_id = key+'_chart';
			const data = JSON.parse(document.getElementById(key+'_data').value);

			const chart_data = new google.visualization.DataTable();
			chart_data.addColumn('datetime', 'Date');
			chart_data.addColumn('number', 'Value');
			for (let i=0; i<data.length; i++) {
				const date = new Date(data[i][1]*1000);
				let value = data[i][0];
				if (key === 'memory' || key === 'storage') {
					value = Math.floor(value/1024/1024/1024*10)/10;
				} else if (key === 'net_rx') {
					value = Math.floor(value/1024/1024*8*10)/10;
				} else if (key === 'net_tx') {
					value = Math.floor(value/1024*8*10)/10;
				}
				chart_data.addRow([date, value]);
			}

			const options = {
				legend: {
					position: 'none',
				},
				//hAxis: {title: 'Year',  titleTextStyle: {color: '#333'}},
				vAxis: {minValue: 0}
			};

			if (key === 'cpu') {
				options.vAxis.maxValue = 100;
				options.vAxis.format = "# '%'";
			} else if (key === 'memory') {
				options.vAxis.format = "# GB";
			} else if (key === 'storage') {
				if (vm_info && vm_info.disk && vm_info.disk.disk_mib) {
					options.vAxis.maxValue = Math.floor(vm_info.disk.disk_mib/1024*10)/10;
				}
				options.vAxis.format = "# GB";
			} else if (key === 'net_rx') {
				options.vAxis.format = "# Mbit/sec";
			} else if (key === 'net_tx') {
				options.vAxis.format = "# Kbit/sec";
			}

			const chart = new google.visualization.AreaChart(document.getElementById(chart_id));
			chart.draw(chart_data, options);
		});
	}
</script>
{/literal}
{/if}
