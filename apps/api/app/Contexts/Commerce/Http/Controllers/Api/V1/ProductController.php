<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Http\Resources\ProductResource;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    /**
     * Public catalogue of purchasable products. `type` narrows to a single kind so the storefront can
     * list bundles on their own page; courses are browsed through the Catalog endpoints instead.
     * Courses are eager-loaded because a bundle is unintelligible without knowing what is in it.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->active()->with(['prices', 'courses']);

        $type = $request->query('type');
        if (is_string($type) && ($case = ProductType::tryFrom($type)) !== null) {
            $query->where('type', $case->value);
        }

        $products = $query->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        return ApiResponse::paginated($products, ProductResource::class);
    }

    /** A single purchasable product by public id, with its prices and the courses it grants. */
    public function show(string $publicId): JsonResponse
    {
        if (! Str::isUuid($publicId)) {
            throw new NotFoundHttpException('Product not found.');
        }

        $product = Product::query()
            ->active()
            ->with(['prices', 'courses'])
            ->where('public_id', $publicId)
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        return ApiResponse::success(new ProductResource($product));
    }
}
