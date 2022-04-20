acf.addFilter('acfe/fields/button/data/name=test_connection', function(data, $el){
    $responseWrapper = $el.find('.test-connection-wrapper');
    $responseDiv = $el.find('.test-connection-response');
    $responseDiv.html('<pre>testing...</pre>');
    $responseWrapper.show();

    // get apikey and apiSecret fields
    data.apikey = $el.parent().find('[data-name="api_key"] input').val();
    data.apisecret = $el.parent().find('[data-name="api_secret"] input').val();
    data.storeid = $el.parent().find('[data-name="store_id"] input').val();

    // return
    return data;
});

acf.addAction('acfe/fields/button/success/name=test_connection', function(response, $el, data){
    $responseDiv = $el.find('.test-connection-response');
    $responseDiv.html("<pre>" + JSON.stringify(response.data, null, 2) + "</pre>");

    // console.log('success');
    // console.log(response);
    // console.log($el);
    // console.log(jQuery.parseJSON(data));
});

jQuery(function($) {
    $('.test-connection-close').click(function(e) {
        $('.test-connection-wrapper').hide();
        $('.test-connection-response').empty();
        return false;
    });
});
