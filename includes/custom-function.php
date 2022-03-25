<?php
    // modify by Jfrost 3/24
    add_filter('jfrost_apply_quantity_based_discount_if_needed', 'jfrost_apply_quantity_based_discount_if_needed', 10, 5);
    add_filter('woocommerce_add_cart_item_data', 'namespace_force_individual_cart_items', 10, 2);

    function namespace_force_individual_cart_items($cart_item_data, $product_id)
    {
        $unique_cart_item_key = md5(microtime() . rand());
        $cart_item_data['unique_key'] = $unique_cart_item_key;
        return $cart_item_data;
    }


    add_action('wp_ajax_woocommerce_get_estimation_subtotal', 'woocommerce_get_estimation_subtotal');
    add_action('wp_ajax_nopriv_woocommerce_get_estimation_subtotal', 'woocommerce_get_estimation_subtotal');

    function woocommerce_get_estimation_subtotal()
    {
        global $wad_last_products_fetch;
        $product_id = $_POST["product_id"];
        $quantity = $_POST["quantity"];
        $variation_id = $_POST["variation_id"];
        $m2_height = $_POST['m2_height'];
        $m2_width = $_POST['m2_width'];
        $m2_value = floatval($m2_height) * floatval($m2_width) / 10000 ; 
        $m2_value = $m2_value == 0 ? 1 : $m2_value;
        // if ( did_action( 'plugin/loaded' ) ) {
        // $discount=new WAD_Discount($product_id);
        // }
        
        $product = wc_get_product($_POST["variation_id"]);
      //  $sale_price = apply_filters('wad_before_calculate_sale_price', $product->get_price() * $m2_value, $product);
        $sale_price = $product->get_price();
        $price = apply_filters('jfrost_apply_quantity_based_discount_if_needed', $product, $sale_price, $quantity,$m2_width,$m2_height) * $m2_value;
        $add_cart_btn_html = wc_price($price * $quantity) ;

        $price_incl_tax = $price + round($price * (19 / 100), 2);
        $price_incl_tax = number_format($price_incl_tax, 2, ",", ".");
        $price = number_format($price, 2, ",", ".");

        $display_price = '<span class="price">';
        $display_price .= '<div class="amount1 jfrost">€ ' . $price .'<small class="woocommerce-price-suffix"> zzgl. 19% MwSt. </small></div>';
        $display_price .= '<br>';
        $display_price .= '<div class="amount2 jfrost">€ ' . $price_incl_tax .'<small class="woocommerce-price-suffix"> inkl. 19% MwSt.</small></div>';
        $display_price .= '</span>';


        $estimate_qty = $quantity;
        $j_cart_key = $variation_id.'_'.$m2_width.'_'.$m2_height;
        $jj = jfrost_get_cart_item_quantities($j_cart_key,$quantity);

        echo wp_send_json(array("price_html"=>$display_price,"add_cart_btn_html"=>$add_cart_btn_html,"price"=>$m2_value,'jfrost_get_cart_item_quantities'=>$jj,'j_cart_key'=>$j_cart_key));
    }
    // modify by Jfrost 3/24
    function jfrost_apply_quantity_based_discount_if_needed($product, $normal_price, $estimate_qty =0,$m2_width,$m2_height)
    {
        global $woocommerce;
        global $wad_settings;
        $inc_shipping_in_taxes = get_proper_value($wad_settings, 'inc-shipping-in-taxes', 'Yes');
        // We check if there is a quantity based discount for this product.
        $product_type = $product->get_type();
        $product_id   = $product->get_id();
        // modify by Jfrost 3/24
        $m2_width = intval($m2_width) > 0 ? $m2_width : 0;
        $m2_height = intval($m2_height) > 0 ? $m2_height : 0;
        $id_to_check = $product->get_id().'_'.$m2_width.'_'.$m2_height;
        if ($product_type == 'variation') {
            $parent_product   = $product->get_parent_id();
            $quantity_pricing = get_post_meta($parent_product, 'o-discount', true);

            if (isset($quantity_pricing[ $product_id ]['enable'])) {
                $quantity_pricing = $quantity_pricing[ $product->get_id() ];
            }
        } else {
            $quantity_pricing = get_post_meta($product_id, 'o-discount', true);
        }

        $normal_price = (float) $normal_price;

        if (empty($quantity_pricing) || ! isset($quantity_pricing['enable'])) {
            return $normal_price;
        }

        $products_qties = jfrost_get_cart_item_quantities($id_to_check, $estimate_qty );
        // var_dump($products_qties);
        if (!isset($products_qties[$id_to_check])) {
            $products_qties[$id_to_check] = $estimate_qty  ;
            // return $normal_price;
        }
        if (array_key_exists($product_id.'_'.$m2_width.'_'.$m2_height, $products_qties)) {
            // $product_qty = $products_qties[ $product_id.'_'.$m2_width.'_'.$m2_height ];
            $product_qty = $products_qties[ $id_to_check];
        }

        if (! isset($product_qty)) {
            return $normal_price;
        }

        $rules_type            = get_proper_value($quantity_pricing, 'rules-type', 'intervals');
        $use_tiering_price     = get_proper_value($quantity_pricing, 'tiered', 'no');
        $original_normal_price = $normal_price;

        if (isset($quantity_pricing['rules']) && $rules_type == 'intervals') {
            if ('yes' === $use_tiering_price) {
                $normal_price = jfrost_apply_tiering_pricing($quantity_pricing, $normal_price, $product_qty);
            } elseif ('no' === $use_tiering_price) {
                $normal_price = jfrost_apply_quantity_based_discount_on_intervals_without_tiering_pricing($quantity_pricing, $normal_price, $product_qty, $original_normal_price);
            }
        } elseif (isset($quantity_pricing['rules-by-step']) && $rules_type == 'steps') {
            $normal_price = jfrost_apply_quantity_based_discount_on_steps($quantity_pricing, $normal_price, $product_qty, $original_normal_price);
        }

        // We save the id of product if qbp is applied on it.
        if ($original_normal_price !== $normal_price) {
            jfrost_save_applied_discount_globally('wad_quantity_based_pricing', $product_id);
        }

        return $normal_price;
    }
    function jfrost_apply_tiering_pricing($quantity_pricing, $normal_price, $product_qty)
    {
        $price_per_interval = array();
        // allows us to obtain a new array of rules with consecutive and ordered indexes.
        $quantity_based_pricing_rules_intervals = array_values($quantity_pricing['rules']);
        foreach ($quantity_based_pricing_rules_intervals as $key => $rule) {
            $min = intval($rule['min']);
            if (0 < $min) {
                $min = --$min;
            }

            // if it is the first round we check if there is a virtual interval.
            if (0 === $key) {
                if (intval($rule['min']) > 1 && intval($rule['min']) <= $product_qty) {
                    $price_per_interval[] = $normal_price * $min;
                }
                if (intval($rule['min']) > $product_qty) {
                    return $normal_price;
                }
            }

            // if it is not the first round we check if there is a virtual interval.
            if (0 < $key) {
                $last_maximum_interval = intval($quantity_based_pricing_rules_intervals[ $key - 1 ]['max']);
                if (($last_maximum_interval + 1) < intval($rule['min'])) {
                    if ($rule['min'] > $product_qty) {
                        $price_per_interval[] = $normal_price * (intval($product_qty) - $last_maximum_interval);
                        break;
                    } else {
                        $price_per_interval[] = $normal_price * ($min - $last_maximum_interval);
                    }
                }

                if ('' === $rule['min']) {
                    $min                                      = $last_maximum_interval;
                    $rule['min']                              = $last_maximum_interval + 1;
                    $quantity_based_pricing_rules_intervals[ $key ]['min'] = $rule['min'];
                }
            }
            // we check if the interval is infinite.
            if ($rule['min'] <= $product_qty && '' === $rule['max']) {
                if (array_key_exists($key + 1, $quantity_based_pricing_rules_intervals)) {
                    $rule['max']                              = intval($quantity_based_pricing_rules_intervals[ $key + 1 ]['min']) - 1;
                    $quantity_based_pricing_rules_intervals[ $key ]['max'] = $rule['max'];
                } else {
                    $nb_products_in_intervals = $product_qty - $min;
                    $price_per_interval[]     = jfrost_get_tiering_price($normal_price, $nb_products_in_intervals, $quantity_pricing['type'], $rule);
                    break;
                }
            }
            // $nb_products_in_intervals represents the number of products in the current range.
            if ($rule['max'] <= $product_qty) {
                $nb_products_in_intervals = intval($rule['max']) - $min;
            } else {
                $nb_products_in_intervals = intval($product_qty) - $min;
            }

            // we calculate the price of the products in the current real range according to the percentage and the number of products in the range.
            $price_per_interval[] = jfrost_get_tiering_price($normal_price, $nb_products_in_intervals, $quantity_pricing['type'], $rule);

            // if the number of product in the cart is in the current interval we stop the loop.
            if (($rule['min'] <= $product_qty && $rule['max'] >= $product_qty)) {
                break;
            }

            // if it is the last interval and there are still some product items to process we calculate their subtotal
            if (! next($quantity_based_pricing_rules_intervals) && $rule['max'] < $product_qty) {
                $remaining_qty = $product_qty - intval($rule['max']);

                $price_per_interval[] = $remaining_qty * $normal_price;
            }
        }

        $normal_price = array_sum($price_per_interval) / $product_qty;

        return $normal_price;
    }
    function jfrost_get_tiering_price($normal_price, $nb_products_in_intervals, $pricing_type, $rule)
    {
        if ('fixedPrice' === $pricing_type) {
            $normal_price = floatval($rule['discount']) * $nb_products_in_intervals;
        } elseif ('fixed' === $pricing_type) {
            $normal_price = ($normal_price - floatval($rule['discount'])) * $nb_products_in_intervals;
        } elseif ('percentage' === $pricing_type) {
            $normal_price = ($normal_price - $normal_price * floatval($rule['discount']) / 100.0) * $nb_products_in_intervals;
        }
        return $normal_price;
    }
    function jfrost_apply_quantity_based_discount_on_intervals_without_tiering_pricing($quantity_pricing, $normal_price, $product_qty, $original_normal_price)
    {
        foreach ($quantity_pricing['rules'] as $rule) {
            if (('' === $rule['min'] && $product_qty <= $rule['max']) || ($rule['min'] <= $product_qty && '' === $rule['max']) || ($rule['min'] <= $product_qty && $product_qty <= $rule['max'])) {
                if ($quantity_pricing['type'] == 'fixed') {
                    $normal_price -= $rule['discount'];
                } elseif ('percentage' === $quantity_pricing['type']) {
                    $normal_price -= ($normal_price * $rule['discount']) / 100;
                } elseif ('n-free' === $quantity_pricing['type']) {
                    $normal_price = jfrost_get_product_free_gift_price($original_normal_price, $product_qty, $rule['discount']);
                } elseif ('fixedPrice' === $quantity_pricing['type']) {
                    $normal_price = $rule['discount'];
                }
                if ('' === $rule['max'] || $product_qty <= $rule['max']) {
                    break;
                }
            }
        }
        return $normal_price;
    }
    function jfrost_apply_quantity_based_discount_on_steps($quantity_pricing, $normal_price, $product_qty, $original_normal_price)
    {
        foreach ($quantity_pricing['rules-by-step'] as $rule) {
            if (0 === $product_qty % $rule['every']) {
                if ('fixed' === $quantity_pricing['type']) {
                    $normal_price -= $rule['discount'];
                } elseif ('percentage' === $quantity_pricing['type']) {
                    $normal_price -= ($normal_price * $rule['discount']) / 100;
                } elseif ('n-free' === $quantity_pricing['type']) {
                    $normal_price = jfrost_get_product_free_gift_price($original_normal_price, $product_qty, $rule['discount']);
                } elseif ('fixedPrice' === $quantity_pricing['type']) {
                    $normal_price = $rule['discount'];
                }
                break;
            }
        }
        return $normal_price;
    }
    function jfrost_get_product_free_gift_price($original_price, $purchased_quantity, $quantity_to_give)
    {
        if ($purchased_quantity == 0) {
            return $original_price;
        }
        $estimated_subtotal           = $purchased_quantity * $original_price;
        $estimated_subtotal_with_gift = $estimated_subtotal - ($original_price * $quantity_to_give);
        $normal_price                 = $estimated_subtotal_with_gift / $purchased_quantity;

        return $normal_price;
    }
    function jfrost_save_applied_discount_globally($key, $value)
    {
        global $wad_applied_discounts;
        if (! array_key_exists($key, $wad_applied_discounts) || ! in_array($value, $wad_applied_discounts[ $key ], true)) {
            $wad_applied_discounts[ $key ][] = $value;
        }
    }

    function jfrost_get_cart_item_quantities($id_to_check, $estimate_qty = 0)
    {
        global $woocommerce;
        $item_qties = array();
        if (isset($woocommerce->cart->cart_contents) && is_array($woocommerce->cart->cart_contents)) {
            foreach ($woocommerce->cart->cart_contents as $cart_item) {
                // modify by Jfrost 3/24
                $wad_qbp_square_size_h = $cart_item['wad_qbp_square_size_h'];
                $wad_qbp_square_size_w = $cart_item['wad_qbp_square_size_w'];
                $j_cart_key = $cart_item["variation_id"].'_'.$wad_qbp_square_size_w.'_'.$wad_qbp_square_size_h;
                if (array_key_exists($j_cart_key,$item_qties)){
                    if (!empty($j_cart_key)) {
                        $item_qties[$j_cart_key] += $cart_item["quantity"];
                        $pid = $j_cart_key;
                    } else {
                        $j_cart_key = $cart_item["product_id"].'_'.$wad_qbp_square_size_w.'_'.$wad_qbp_square_size_h;
                        $item_qties[$j_cart_key] += $cart_item["quantity"];
                        $pid = $j_cart_key;
                    }
                }else{
                    $item_qties[$j_cart_key] = $cart_item["quantity"];
                }
                // if ($id_to_check == $pid ){
                //     // $item_qties[$pid] = $item_qties[$pid] ;
                //     $item_qties[$pid] = $item_qties[$pid] +$estimate_qty;
                // }
            }
            $item_qties[$id_to_check] = $item_qties[$id_to_check] +$estimate_qty;
        }
        return $item_qties;
    }


    /**
     * Update header mini-cart contents when products are added to the cart via AJAX
     */
    add_filter('woocommerce_add_to_cart_fragments', 'trusted_woo_header_add_to_cart_fragments_jfrost_version', 100, 1);
    add_filter('woocommerce_cart_contents_total', 'get_cart_subtotal', 100, 1);
    function get_cart_subtotal($subtotal)
    {
        return WC()->cart->get_cart_subtotal();
    }
    function trusted_woo_header_add_to_cart_fragments_jfrost_version($fragments)
    {
        $tel_no = get_theme_mod('tel_no', '');
        if (!$tel_no) {
            $cart_background = ' no-background';
        } else {
            $cart_background = '';
        }

        $woo_top_cart_link = wc_get_cart_url();

        if (WC()->cart->display_cart_ex_tax) {
            // $cart_contents_total = wc_price( WC()->cart->cart_contents_total );
            $cart_contents_total = wc_price(WC()->cart->get_cart_subtotal());
        } else {
            // $cart_contents_total = wc_price( WC()->cart->cart_contents_total + WC()->cart->tax_total );
            $cart_contents_total = wc_price(WC()->cart->get_cart_subtotal() + WC()->cart->tax_total);
        }
        $cart_contents_total = strip_tags(apply_filters('woocommerce_cart_contents_total', $cart_contents_total));

        ob_start(); ?>

                    <div class="top-cart jfrost-function">
                        <a class="cart-contents<?php echo $cart_background; ?>" href="<?php echo esc_url($woo_top_cart_link); ?>"><i class="fa cart-icon"><?php echo sprintf('<span class="item-count">%d</span>', WC()->cart->get_cart_contents_count()); ?></i><?php echo esc_html($cart_contents_total); ?></a>
                        <div class="top-login-mini-cart">
                            <?php woocommerce_mini_cart(); ?>		
                        </div>
                    </div>
        <?php

        $fragments['.top-cart'] = ob_get_clean();

        return $fragments;
    }

    //add_filter('woocommerce_product_variation_get_price', 'get_sale_price', 99999999, 3);
    // function get_sale_price($sale_price, $product, $include_quantity_based_pricing=true)
    // {
    //     if ($include_quantity_based_pricing && (is_cart() || is_checkout() || did_action('woocommerce_before_mini_cart_contents'))) {
    //         $sale_price = apply_filters('wad_before_calculate_sale_price', $product->get_regular_price(), $product);
    //         // modify by Jfrost 3/24
    //         $sale_price = apply_filters('jfrost_apply_quantity_based_discount_if_needed', $product, $sale_price, 1,$m2_width,$m2_width);
    //     }
    //     return $sale_price;
    // }
?>