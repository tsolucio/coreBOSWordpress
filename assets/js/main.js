/*
 * coreBOS WP Plugin Javascript file.
 */


// Post handling booking service
function wbk_on_booking(service, time, name, email, phone, desc, quantity) {
    // Post
    jQuery.ajax({
        url: cbwpJS.ajaxurl,
        type: 'post',
        data: {
            action: 'save_project',
            service: service,
            time: time,
            desc: desc,
        }
    });
}