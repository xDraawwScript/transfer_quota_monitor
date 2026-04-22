(function (OC, $) {
    if (window.location.href.indexOf('/apps/files') === -1) return;

    $(document).ready(function () {
        const updateTransferQuota = function () {
            $.ajax({
                url: OC.generateUrl('/apps/transfer_quota_monitor/quota/self'),
                type: 'GET',
                success: function (quota) {
                    if (quota.limit <= 0) return;
                    const usageGB = (quota.usage / (1024 * 1024 * 1024)).toFixed(2);
                    const limitGB = (quota.limit / (1024 * 1024 * 1024)).toFixed(0);
                    const percent = Math.min((quota.usage / quota.limit) * 100, 100);
                    let barColor = 'var(--color-primary)';
                    if (percent >= 95) barColor = '#ef4444';
                    else if (percent >= 80) barColor = '#f59e0b';
                    $('#transfer-quota-floating').remove();
                    const $transferQuota = $(`
                        <div id="transfer-quota-floating" style="border-left: 5px solid ${barColor};">
                            <div class="tqm-header">
                                <span class="tqm-title">Transfer data used today :</span>
                                <span class="tqm-values">${usageGB} / ${limitGB} Go</span>
                            </div>
                            <div class="tqm-progress-container">
                                <div class="tqm-progress-bar" style="width: ${percent}%; background-color: ${barColor};"></div>
                            </div>
                        </div>
                    `);
                    $('body').append($transferQuota);
                }
            });
        };

        updateTransferQuota();
        setInterval(updateTransferQuota, 5000);
    });
})(OC, jQuery);