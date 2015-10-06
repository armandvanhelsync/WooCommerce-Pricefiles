<?php

/**
 * Base file or specific pricefile genereators
 *
 * @author peter
 */
abstract class WC_Pricefile_Generator
{

    /**
     * Base file or specific pricefile genereators
     *
     * @since 0.1.0
     *
     * @var object
     */
    private static $_instances = array();
    
    protected $options = array();
    protected $shipping_methods = array();
    protected $shipping_destination = array();
    protected $plugin_slug = array();
    public $price_type = null;
    public $pricefile_slug = null;

    //Default CSV separators
    const VALUE_SEPARATOR = ';';
    const VALUE_ENCLOSER_BEFORE = '"';
    const VALUE_ENCLOSER_AFTER = '"';

    /*
     * Tell generator implementation to start a new pricefile
     *
     * @since 0.1.12
     */
    protected abstract function print_header();

    /*
     * Tell generator implementation about a product
     *
     * @since 0.1.12
     */
    protected abstract function print_product($product_info);

    /*
     * Tell generator implementation to wrap up pricefile
     *
     * @since 0.1.12
     */
    protected abstract function print_footer();

    public function __construct($pricefile_slug)
    {
        include_once WP_PRICEFILES_PLUGIN_PATH . 'includes/product.php';

        $this->options = WC_Pricefiles()->get_options();
        
        ignore_user_abort(true);
        
        if ((!empty($this->options['disable_timeout']) && $this->options['disable_timeout'] == 1) ) {
            if(!@set_time_limit(0)) {
                //TODO: Debug log: Could not set time limit
            }
        }
        
        if ((!empty($this->options['set_memory_limit']) && $this->options['set_memory_limit'] == 1) ) {
            $this->set_memory_limit();
        }
        
        $this->pricefile_slug = $pricefile_slug;
        
        include_once WP_PRICEFILES_PLUGIN_PATH . 'includes/admin.php';
        include_once WP_PRICEFILES_PLUGIN_PATH . 'includes/admin/options.php';

        $this->shipping_destination = WC_Pricefiles()->get_shipping_destination_values();
        $this->shipping_methods = WC_Pricefiles()->get_shipping_methods();
    }
    
    final public static function get_instance($slug)
    {
        $calledClass = get_called_class();

        if (!isset(self::$_instances[$calledClass])) {
            self::$_instances[$calledClass] = new $calledClass($slug);
        }

        return self::$_instances[$calledClass];
    }
    
    final public static function get_instances()
    {
        return self::$_instances;
    }

    /**
     * Genereates the pricefile
     * 
     * @since 0.1.0
     */
    public function generate_pricefile()
    {
        if($this->read_cache()) {
            die();
        }

        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'nopaging' => true
        );

        $loop = new WP_Query($args);

        if ($loop->have_posts()) {
            //Generate file header
            $this->print_header();

            //Get list of excluded products
            if (empty($this->options['exclude_ids'])) {
                $excluded_ids = array();
            } 
            else
            {
                $excluded_ids = $this->options['exclude_ids'];
            }
            
            $count = 0;
            $variants_count = 0;
            $hidden_count = 0;
            $excluded_count = 0;
            
            while ($loop->have_posts())
            {
                $loop->the_post();

                $product_id = get_the_id();

                if (in_array($product_id, $excluded_ids)) {
                    $excluded_count++;
                    continue;
                }
                
                $product = new WC_Pricefiles_Product($product_id);
                
                if($product->show_variations()) {
                    $available_variations = $product->get_variations();
                    
                    if(is_array($available_variations)) {
                        foreach($available_variations AS $variation)
                        {
                            //Instantiate product variation
                            $product_variation = new WC_Pricefiles_Product($variation['variation_id']);
                            
                            //Tell generator implementation to print this product
                            $this->print_product($product_variation);
                            $variants_count++;
                        }
                    }
                }
                elseif($product->show() ) {
                    //Tell generator implementation to print this product
                    $this->print_product($product);
                    $count++;
                }
                else
                {
                    $hidden_count++;
                }
            }

            //Generate file footer
            $this->print_footer();
            
            return array(
                'product_count' => $count,
                'variants_count' => $variants_count,
                'excluded_count' => $excluded_count,
                'hidden_count' => $hidden_count,
                'status'        => $this->save_cache()
            );
        } 
        else
        {
            if($this->is_debug()) {
                echo 'No products found';
                return false;
            }
        }
    }
    
    function set_memory_limit() 
    {
        $ml = ini_get('memory_limit');

        //Unlimited. No need to set limit
        if($ml == "-1") {
            return true;
        }
        
        preg_match('/(\d{1,10})([a-zA-Z]{1,2})/', $ml, $matches);
        
        //If memory limit is under 2G, try to set it to 2G
        if(!is_array($matches) || empty($matches) 
            || ( $matches[2] == 'G' && $matches[1] < 2 )  
            || ($matches[2] == 'M' && $matches[1] < 2048) 
            || ($matches[2] == 'K' && $matches[1] < 2048000)
        ) {
            ini_set('memory_limit', '2048M');

            $new_ml = ini_get('memory_limit');

            if($new_ml != '2048M') {
                //TODO: Debug log: Could not set memory limit
                if($this->is_debug()) {
                    echo 'Cound not set memory limit (Limit:'.$ml.')';
                }
                return false;
            }
        }
    }

    /**
     * Check if cache is activated
     * 
     * @return bool   
     */
    protected function use_cache()
    {
        if (empty($this->options['use_cache']) || (!empty($this->options['use_cache']) && $this->options['use_cache'] != 1) ) {
            return false;
        }
        else
        {
            return true;
        }
    }

    /**
     * Check if cache can be written
     * 
     * @return bool   
     */
    protected function can_read_cache()
    {
        if (!$this->use_cache() ) {
            return false;
        }
        
        
        if (!empty($_GET['refresh']) && $_GET['refresh'] == 1) {            
            return false;
        }
        
        /*
        if( $this->options['cache_timeout'] > 0)
        {
            if ( ( $time = get_transient(WC_PRICEFILES_PLUGIN_SLUG . '_file_cache_time_' . $this->pricefile_slug) ) === false )
            {
                if($this->is_debug())
                {
                    echo 'Cache file time expired. Cache timeout: '.$this->options['cache_timeout'];
                }
                return false;
            }
        }
        */
            
        
        return true;
    }
    
    /**
     * Read pricefile form cache
     * 
     * @return boolstring  Status   
     */
    function read_cache()
    {
        if($this->can_read_cache() && $this->use_cache()) {
            $cache_path = WP_CONTENT_DIR . '/cache/' . WC_PRICEFILES_PLUGIN_SLUG . '/' . $this->pricefile_slug . '.txt';

            if (file_exists($cache_path)) {
                if($this->is_debug()) {
                    echo 'serverd from cache: ';
                }
                
                echo file_get_contents($cache_path);

                return true;
            }
            else
            {
                if($this->is_debug()) {
                    echo 'Cache file not found';
                }
            }
        }
        
        if ($this->use_cache() ) {
            ob_start();
        }
        
        return false;
    }

    /**
     * Save generated pricefile to cache
     * 
     * @return string  Status   
     */
    protected function save_cache()
    {
        if (!$this->use_cache() ) {
            return 'no_cache';
        }
        
        $data = ob_get_clean();
        
        $cache_path = WP_CONTENT_DIR . '/cache/' . WC_PRICEFILES_PLUGIN_SLUG . '/' . $this->pricefile_slug . '.txt';

        //If files does not exist, test if we can create it
        if(!file_exists($cache_path)) {
            $w = file_put_contents($cache_path, '.');
        }
        
        if (!is_writable($cache_path)) {
            if (!file_exists(dirname($cache_path))) {
                if (!mkdir(dirname($cache_path), 0777, true)) {
                    if($this->is_debug()) {
                        echo 'Could not create cache path (' . $cache_path . '). Not writable by PHP';
                    }
                }
            } else
            {
                if($this->is_debug()) {
                    echo 'Cache path (' . $cache_path . ') is not writable by PHP';
                }
            }
            $return = 'not_writable';
        } else
        {
            $return = file_put_contents($cache_path, $data);
            if ($return && $return > 0) {
                update_option(WC_PRICEFILES_PLUGIN_SLUG . '_cache_last_updated_' . $this->pricefile_slug, current_time('timestamp'));
                //set_transient(WC_PRICEFILES_PLUGIN_SLUG . '_file_cache_time_' . $this->pricefile_slug, time(), 25 * HOUR_IN_SECONDS);
                $return = 'cache_written';
            }
            else
            {
                $return = 'cache_not_writable';
                if($this->is_debug()) {
                    echo 'Cache not written';
                }
            }
        }
        
        if($this->is_debug()) {
            echo ucfirst($this->pricefile_slug).' cache status: '.$return."\n";
        }
        
        if(empty($_GET['output']) || $_GET['output'] != 'json') {
            echo $data;
            // Stop execution completely to prevent garbage data (unlike wp_die()). 
            die(); 
        }
        else
        {
            return $return;
        }
    }
    
    /**
     * Is debug more on?
     * 
     * @return bool   
     */
    function is_debug()
    {
        if(!empty($this->options['use_debug']) && $this->options['use_debug'] == 1) {
            return true;
        }
        else 
        {
            return false;
        }
    }

    
    /**
     * Formats the value for output in pricefile and adds the required field separators.
     * 
     * @param  string/numeric  Value to be formatted
     * @return string   Formatted value
     */
    public static function format_value($value)
    {
        if (empty($value) && $value !== 0 && $value !== 0.0 ) {
            $value = '';
        }
        
        $c = get_called_class();

        return $c::VALUE_ENCLOSER_BEFORE . addcslashes($value, '"\\') . $c::VALUE_ENCLOSER_AFTER . $c::VALUE_SEPARATOR;
    }

}

/*
 * This function exist only in PHP >= 5.3.
 * For for previous versions, we need to emulate this function.
 * Ugly hack, but it works.
 */
if (!function_exists('get_called_class')) {
    function get_called_class()
    {
        $bt = debug_backtrace();
        $l = 0;
        do
        {
            $l++;
            $lines = file($bt[$l]['file']);
            $callerLine = $lines[$bt[$l]['line']-1];
            preg_match('/([a-zA-Z0-9\_]+)::'.$bt[$l]['function'].'/', $callerLine, $matches);
        } while ($matches[1] === 'parent' && $matches[1]);

        return $matches[1];
    }
}

?>
