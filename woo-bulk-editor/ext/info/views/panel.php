<?php
if (!defined('ABSPATH'))
    die('No direct access allowed');

global $WOOBE;
?>

<h4><?php esc_html_e('Help', 'woo-bulk-editor') ?></h4>

<div class="woobe_alert">
    <?php
    printf(esc_html__('The plugin has %s, %s, %s list. Also if you have troubles you can %s!', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.com/documentation/',
                'title' => esc_html__('documentation', 'woo-bulk-editor'),
                'target' => '_blank'
            )), WOOBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.com/how-to-list/',
                'title' => esc_html__('FAQ', 'woo-bulk-editor'),
                'target' => '_blank'
            )), WOOBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.com/translations/',
                'title' => esc_html__('translations', 'woo-bulk-editor'),
                'target' => '_blank'
            )), WOOBE_HELPER::draw_link(array(
                'href' => 'https://pluginus.net/support/forum/woobe-woocommerce-bulk-editor-professional/',
                'title' => '<b style="color: #2eca8b;">' . esc_html__('ask for support here', 'woo-bulk-editor') . '</b>',
                'style' => 'text-decoration: none;',
                'target' => '_blank'
    )));
    ?>
</div>

<?php if ($WOOBE->show_notes) : ?>
    <div style="height: 9px;"></div>
    <div class="woobe_set_attention woobe_alert"><?php
        printf(esc_html__('Current version of the plugin is FREE. See the difference between FREE and PREMIUM versions %s', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.com/downloads/',
                    'title' => esc_html__('here', 'woo-bulk-editor'),
                    'target' => '_blank'
        )));
        ?></div>
<?php endif; ?>
</b>


<h4><?php esc_html_e('Some little hints', 'woo-bulk-editor') ?>:</h4>

<ul>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('If to click on an empty space of the black wp-admin bar, it will get you back to the top of the page', 'woo-bulk-editor') ?></li>


    <li><span class="icon-right"></span>&nbsp;<?php
        printf(esc_html__('Can I %s?', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.com/howto/can-i-select-products-and-add-15-to-their-regular-price/',
                    'title' => esc_html__('select products and add 15% to their regular price', 'woo-bulk-editor'),
                    'target' => '_blank'
                )))
        ?>
    </li>

    <li><span class="icon-right"></span>&nbsp;<?php
        printf(esc_html__('How to %s by bulk operation', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.com/howto/how-to-remove-sale-prices-by-bulk-operation/',
                    'title' => esc_html__('remove sale prices', 'woo-bulk-editor'),
                    'target' => '_blank',
                    'style' => 'color: red;'
                )))
        ?>
    </li>

    <li><span class="icon-right"></span>&nbsp;<?php
        printf(esc_html__('If your shop is on the Russian language you should install %s for the correct working of BEAR with Cyrillic', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://ru.wordpress.org/plugins/cyr2lat/',
                    'title' => esc_html__('this plugin', 'woo-bulk-editor'),
                    'target' => '_blank'
                )))
        ?>
    </li>


    <li><span class="icon-right"></span>&nbsp;<?php
        printf(esc_html__('How to set the same value for some products on the same time - %s', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.com/howto/how-to-set-the-same-value-for-some-products-on-the-same-time/',
                    'title' => esc_html__('binded editing', 'woo-bulk-editor'),
                    'target' => '_blank'
                )))
        ?>
    </li>

    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Remember! "Sale price" can not be greater than "Regular price", never! So if "Regular price" is 0 - not possible to set "Sale price"!', 'woo-bulk-editor') ?>
    </li>

    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Search by products slugs, which are in non-latin symbols does not work because in the data base they are keeps in the encoded format!', 'woo-bulk-editor') ?>
    </li>


    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Click ID of the product in the products table to see it on the site front.', 'woo-bulk-editor') ?>
    </li>


    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Use Enter keyboard button in the Products Editor for moving to the next products row cell with saving of changes while edit textinputs. Use arrow Up or arrow Down keyboard buttons in the Products Editor for moving to the next/previous products row without saving of changes.', 'woo-bulk-editor') ?>
    </li>

    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('To select range of products using checkboxes - select first product, press SHIFT key on your PC keyboard and click last product.', 'woo-bulk-editor') ?>
    </li>

    <li><span class="icon-right"></span>&nbsp;<?php
        printf(esc_html__('If you have any ideas, you can suggest them on %s', 'woo-bulk-editor'), WOOBE_HELPER::draw_link(array(
                    'href' => 'https://pluginus.net/support/forum/woobe-woocommerce-bulk-editor-professional/',
                    'title' => esc_html__('the plugin forum', 'woo-bulk-editor'),
                    'target' => '_blank'
                )))
        ?>
    </li>
</ul>

<hr />

<h4><?php esc_html_e('Some useful articles', 'woo-bulk-editor') ?>:</h4>

<ul>

    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/bulk-delete-sale-price-in-woocommerce-store',
            'title' => esc_html__('Bulk delete sale price in WooCommerce store', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/how-to-apply-sale-prices-in-bulk-to-a-specific-category',
            'title' => esc_html__('How to apply Sale Prices in Bulk to a specific category', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    

    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/quickly-update-the-stock-status-of-all-products-in-a-particular-category',
            'title' => esc_html__('Quickly update the stock status of all products in a particular category', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/increase-woocommerce-product-prices-by-a-specific-percentage-in-a-specific-category-a-guide-to-bulk-price-updates',
            'title' => esc_html__('Increase WooCommerce Product Prices by a Specific Percentage in a specific category: A Guide to Bulk Price Updates', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/efficiently-remove-woocommerce-products-in-bulk-from-specific-categories-or-attributes',
            'title' => esc_html__('Efficiently Remove WooCommerce Products in Bulk from Specific Categories or Attributes', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/efficiently-update-stock-status-for-specific-woocommerce-category-products',
            'title' => esc_html__('Efficiently Update Stock Status for Specific WooCommerce Category Products', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/bulk-edit-and-update-the-shipping-class-for-products-in-a-designated-woocommerce-category',
            'title' => esc_html__('Bulk edit and update the shipping class for products in a designated woocommerce category', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/bulk-update-custom-fields-across-a-specific-woocommerce-category-a-comprehensive-guide',
            'title' => esc_html__('Bulk Update Custom Fields Across a Specific WooCommerce Category: A Comprehensive Guide', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
    
    
    
    <li>
        <span class="icon-right"></span>&nbsp;<?php
        echo WOOBE_HELPER::draw_link(array(
            'href' => 'https://paradigma.tools/post/bulk-update-sku-numbers-for-products-in-a-specific-woocommerce-category',
            'title' => esc_html__('Bulk Update SKU Numbers for Products in a Specific WooCommerce Category', 'woo-bulk-editor'),
            'target' => '_blank'
        ));
        ?>
    </li>
</ul>

<hr />
<br>


<div class="woobe_alert">
<?php
printf(esc_html__('If you like BEAR %s about what you liked and what you want to see in future versions of the plugin', 'woo-bulk-editor'), WOOBE_HELPER::draw_link([
            'href' => $WOOBE->show_notes ? 'https://wordpress.org/support/plugin/woo-bulk-editor/reviews/?filter=5#new-post' : 'https://codecanyon.net/downloads#item-21779835',
            'target' => '_blank',
            'title' => esc_html__('write us feedback please', 'woo-bulk-editor'),
            'class' => ''
        ]));
?>
</div>

<h4><?php esc_html_e('Requirements', 'woo-bulk-editor') ?>:</h4>
<ul>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Recommended min RAM', 'woo-bulk-editor') ?>: 1024 MB</li>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Minimal PHP version is', 'woo-bulk-editor') ?>: PHP 7.2</li>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Recommended PHP version', 'woo-bulk-editor') ?>: 8.xx</li>
</ul><br />



<hr />
<h4><?php esc_html_e('Some useful plugins for your e-shop', 'woo-bulk-editor') ?></h4>


<div class="col-lg-12">
    <a href="https://products-filter.com/" title="WOOF - WooCommerce Products Filter" target="_blank">
        <img width="200" src="<?php echo WOOBE_LINK ?>assets/images/woof_banner.png" alt="WOOF - WooCommerce Products Filter" />
    </a>

    <a href="https://currency-switcher.com/" title="WOOCS - WooCommerce Currency Switcher" target="_blank">
        <img width="200" src="<?php echo WOOBE_LINK ?>assets/images/woocs_banner.png" alt="WOOCS - WooCommerce Currency Switcher" />
    </a>

    <a href="https://products-tables.com/" title="WOOT - WooCommerce Active Products Tables" target="_blank">
        <img width="200" src="<?php echo WOOBE_LINK ?>assets/images/woot_banner.png" alt="WOOT - WooCommerce Active Products Tables" />
    </a>

</div>

<div class="clear"></div>


