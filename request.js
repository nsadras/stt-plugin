(function($){
    $(document).ready(function() {
        $(".js-stt-button").click(function(event){
            event.preventDefault();
            var button_element = $(this);
            button_element.attr('disabled', true).val('Sending...');
            $.ajax(ajaxurl, {
                data: {
                    action: 'stt_update',
                    order_schema: $(".js-stt-dropdown").val(), 
                    post_id: GLOBAL_post_id,
                    security: GLOBAL_ajax_nonce
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    button_element.attr('disabled', false).val('Error. (Try again?)');
                    console.log(jqXHR.responseText);
                },
                success: function(data, textStatus, jqXHR) {
                    if (jqXHR.status == 200) {
                        button_element.attr('disabled', false).val('(Finished) Send to Top');
                    } else {
                        button_element.attr('disabled', false).val('Error. (Try again?)');
                        console.log('Got status: ' + jqXHR.status);
                        console.log(jqXHR.responseText);
                    }
                },
                type: 'POST'
            });
        });
    });
})(jQuery);
