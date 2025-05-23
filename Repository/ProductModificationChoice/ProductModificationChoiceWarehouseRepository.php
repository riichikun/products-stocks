<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Products\Stocks\Repository\ProductModificationChoice;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Products\Category\Entity\Offers\Variation\Modification\CategoryProductModification;
use BaksDev\Products\Category\Entity\Offers\Variation\Modification\Trans\CategoryProductModificationTrans;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Type\Id\ProductUid;
use BaksDev\Products\Product\Type\Offers\ConstId\ProductOfferConst;
use BaksDev\Products\Product\Type\Offers\Variation\ConstId\ProductVariationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\ConstId\ProductModificationConst;
use BaksDev\Products\Stocks\Entity\Total\ProductStockTotal;
use BaksDev\Users\User\Type\Id\UserUid;
use Generator;
use InvalidArgumentException;

final class ProductModificationChoiceWarehouseRepository implements ProductModificationChoiceWarehouseInterface
{

    private ?UserUid $user = null;
    private ?ProductUid $product = null;
    private ?ProductOfferConst $offer = null;
    private ?ProductVariationConst $variation = null;

    public function __construct(private readonly DBALQueryBuilder $DBALQueryBuilder) {}


    public function user(UserUid|string $user): self
    {
        if(is_string($user))
        {
            $user = new UserUid($user);
        }

        $this->user = $user;

        return $this;
    }


    public function product(ProductUid|string $product): self
    {
        if(is_string($product))
        {
            $product = new ProductUid($product);
        }

        $this->product = $product;

        return $this;
    }


    public function offerConst(ProductOfferConst|string $offer): self
    {
        if(is_string($offer))
        {
            $offer = new ProductOfferConst($offer);
        }

        $this->offer = $offer;

        return $this;
    }

    public function variationConst(ProductVariationConst|string $variation): self
    {
        if(is_string($variation))
        {
            $variation = new ProductVariationConst($variation);
        }

        $this->variation = $variation;

        return $this;
    }


    /**
     * Метод возвращает все идентификаторы множественных вариантов, имеющиеся в наличии на склад
     */
    public function getProductsModificationExistWarehouse(): Generator
    {
        if(!$this->user || !$this->product || !$this->offer || !$this->variation)
        {
            throw new InvalidArgumentException('Необходимо передать все параметры');
        }

        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();


        $dbal->from(ProductStockTotal::class, 'stock');

        $dbal
            ->andWhere('stock.usr = :usr')
            ->setParameter('usr', $this->user, UserUid::TYPE);

        $dbal->andWhere('stock.product = :product')
            ->setParameter('product', $this->product, ProductUid::TYPE);


        $dbal->andWhere('stock.offer = :offer')
            ->setParameter('offer', $this->offer, ProductOfferConst::TYPE);


        $dbal->andWhere('stock.variation = :variation')
            ->setParameter('variation', $this->variation, ProductVariationConst::TYPE);

        $dbal->andWhere('(stock.total - stock.reserve) > 0');


        $dbal->join(
            'stock',
            Product::class,
            'product',
            'product.id = stock.product',
        );


        $dbal->join(
            'stock',
            ProductOffer::class,
            'offer',
            'offer.const = stock.offer AND offer.event = product.event',
        );


        $dbal->join(
            'stock',
            ProductVariation::class,
            'variation',
            'variation.const = stock.variation AND variation.offer = offer.id',
        );


        $dbal->join(
            'stock',
            ProductModification::class,
            'modification',
            'modification.const = stock.modification AND modification.variation = variation.id'
        );


        // Тип торгового предложения

        $dbal->join(
            'modification',
            CategoryProductModification::class,
            'category_modification',
            'category_modification.id = modification.category_modification'
        );

        $dbal->leftJoin(
            'category_modification',
            CategoryProductModificationTrans::class,
            'category_modification_trans',
            'category_modification_trans.modification = category_modification.id AND category_modification_trans.local = :local'
        );


        $dbal->addSelect('stock.modification AS value')->groupBy('stock.modification');
        $dbal->addSelect('modification.value AS attr')->addGroupBy('modification.value');
        $dbal->addSelect('category_modification_trans.name AS option')->addGroupBy('category_modification_trans.name');

        $dbal->addSelect('(SUM(stock.total) - SUM(stock.reserve)) AS property');
        $dbal->addSelect('modification.postfix AS characteristic')->addGroupBy('modification.postfix');
        $dbal->addSelect('category_modification.reference AS reference')->addGroupBy('category_modification.reference');


        return $dbal
            ->enableCache('products-stocks', 86400)
            ->fetchAllHydrate(ProductModificationConst::class);

    }
}
