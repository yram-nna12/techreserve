<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title><?= esc($title) ?></title></head>
<body style="margin:0;background:#f2f5f7;color:#172d3c;font-family:Arial,sans-serif;line-height:1.6">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f5f7;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #d8e2e8;border-radius:8px">
            <tr><td style="padding:24px 28px;border-bottom:1px solid #d8e2e8;color:#12605f;font-size:22px;font-weight:bold"><?= esc($brand) ?></td></tr>
            <tr><td style="padding:24px 28px">
                <h1 style="font-size:22px;line-height:1.3"><?= esc($title) ?></h1>
                <p><?= esc($message) ?></p>
                <table role="presentation" cellspacing="0" cellpadding="6">
                    <?php foreach ($details as $detail): ?>
                        <tr><th align="left"><?= esc($detail['label']) ?></th><td><?= esc($detail['value']) ?></td></tr>
                    <?php endforeach ?>
                </table>
                <p style="margin-top:24px"><a href="<?= esc($reservationUrl, 'attr') ?>" style="display:inline-block;padding:10px 16px;background:#12605f;color:#fff;text-decoration:none;border-radius:4px">View reservation</a></p>
                <p style="font-size:12px;color:#536b78">This message contains no payment credentials or account numbers. Sign in to TechReserve to view your reservation.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
