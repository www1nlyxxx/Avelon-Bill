<a class="btn btn-primary" href="clientarea.php?action=productdetails&id={$serviceid}&ip=1">Назад</a>
<h2>Изменение IP-адреса</h2>
{if $allow_change_ptr}
{if $updated}
<div class="alert alert-success">IP-адрес успешно изменен</div>
{/if}
{if !$ip_info}
<div class="alert alert-danger">IP-адрес не найден</div>
{else}
<form method="POST">
    <div class="row">
        <div class="col-sm-6">
            <div class="form-group">
                <label for="inputDomain" class="control-label">Домен</label>
                <input type="text" name="domain" id="inputDomain" value="{$ip_info->domain}" class="form-control">
            </div>
        </div>
    </div>
    <input class="btn btn-primary" type="submit" name="save" value="Сохранить изменения">
</form>
{/if}
{/if}
