(function($){
    $(document).ready(function() {
        $('.js-stt-dropdown').change(function (){
            $('.js-stt-button').attr('disabled',
                $(this).val() == "NULL");
        });
        $(".js-stt-button").click(function(event){
            event.preventDefault();
            var button_element = $(this);
            button_element.attr('disabled', true).val('Sending...');
            if (GLOBAL_post_id && GLOBAL_post_id != -1) {
                var post_id = GLOBAL_post_id;
            } else {
                var post_id = $(this).parents("tr").attr("id");
                if (!post_id.match(/^edit-\d+$/)) {
                    button_element.attr('disabled', true).val('Internal error. (Please notify online team)');
                    return;
                } else {
                    post_id = post_id.substring("edit-".length);
                }
            }
            $.ajax(ajaxurl, {
                data: {
                    action: 'stt_update',
                    order_schema: $(".js-stt-dropdown").val(), 
                    post_id: post_id,
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
        $(".editinline").on('click', function(event) {
            console.log(tag_id);
        });
    });
})(jQuery);
