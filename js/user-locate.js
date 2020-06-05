jQuery(document).ready(function () {
    var hash = location.hash;
    console.log(location.href);
    if (hash.indexOf('access_token') > -1) {
        jQuery.ajax({
            url: COS_DATA.admin_url + '?action=citizenos-connect-authorize' + hash.replace('#', '&'),
            success: function (data) {
                console.log('RESPONSE', data);
                window.open(location.href = location.origin + location.pathname + location.search);
                location.href = location.origin + location.pathname;
            },
            error: function (err) {
                console.log('ERROR', err);
            }
        })
    }
});