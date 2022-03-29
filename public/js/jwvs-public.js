(function( $ ) {
	'use strict';
	var timeout;

	jQuery( function( $ ) {
		$(document).on('change', '.woocommerce-cart .quantity.buttons_added input.qty', function(){
			if ( timeout !== undefined ) {
				clearTimeout( timeout );
			}
			timeout = setTimeout(function() {
                $("[name='update_cart']").trigger("click");
			}, 1000 ); // 1 second delay, half a second (500) seems comfortable too
	
		});
	} );

	$(document).on('change', '.single-product .quantity.buttons_added input.qty', function (e) {
        e.preventDefault();
        var $qty_field = $(this),
		product_qty = $qty_field.val(),
		$cart_field = $qty_field.closest('.woocommerce-variation-add-to-cart'),
		product_id = $cart_field.find('input[name="product_id"]').val(),
		variation_id = $cart_field.find('input[name="variation_id"]').val() || 0,
		m2_height = $('.wad-custom-square.variations input[name="o-discounts[qbp][square][h]"]').val() || 0,
		m2_width = $('.wad-custom-square.variations input[name="o-discounts[qbp][square][w]"]').val() || 0;
        var data = {
			action: 'woocommerce_get_estimation_subtotal',
            product_id: product_id,
            quantity: product_qty,
            variation_id: variation_id,
            m2_height: m2_height,
            m2_width: m2_width,
		};
        $.ajax({
            type: 'post',
            url: jwvs_ajax_object.ajax_url,
            data: data,
            beforeSend: function (response) {
                $(".single_add_to_cart_button").html('calculating...');
            },
            complete: function (response) {
            },
            success: function (response) {
                if (response.error) {
                    location.reload();
                    return;
                } else {
                    $(".woocommerce-variation-price").html(response.price_html);
                    $(".single_add_to_cart_button").html(response.add_cart_btn_html);
                }
            },
        });

        return false;
    });
    $(document).on('change click dbclick', "input[name='o-discounts[qbp][square][w]']", function(){
        $('.single-product .quantity.buttons_added input.qty').trigger('change');
    });
    $(document).on('change click dbclick', "input[name='o-discounts[qbp][square][h]']", function(){
        $('.single-product .quantity.buttons_added input.qty').trigger('change');
    });
    
})( jQuery );
