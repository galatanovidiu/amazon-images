<?php
/**
 *
 */

namespace Galatanovidiu\Amazonimage;
use Galatanovidiu\Amazonimage\GetProductImageAmazon;
use Galatanovidiu\Amazonimage\GetProductImageAWS;

class GetProductImage
{

    protected $asin;

    public function __construct($asin)
    {
        $this->asin = $asin;
    }

    public function get_product_images()
    {

        // GEt images from
        $images = (new GetProductImageAWS)->get_images($this->asin);
        if(count($images) < 1){
            $images = (new GetProductImageAmazon)->get_images($this->asin);
        }

        return $images;
    }



}