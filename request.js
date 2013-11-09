(function($){
    $(document).ready(function() {
        $("#stt_button").click(function(event){
            event.preventDefault();
            var data = {
                order_scheme: $("#stt_dropdown").val(), 
                post_id: GLOBAL_post_id,
                action: 'stt_update',
            };
            console.log("post id: " + GLOBAL_post_id);
            $.post(ajaxurl, data, function(response){
                console.log("Response: " + response);
            });
        });
    });
})(jQuery);
