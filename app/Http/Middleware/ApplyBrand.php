<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Branding\BrandResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Private label: picks the brand for the request host and shares it with every view as $brand. */
final class ApplyBrand
{
    public function __construct(private readonly BrandResolver $brands) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $brand = $this->brands->forHost($request->getHost());
        } catch (\Throwable) {
            $brand = \App\Support\Branding\Brand::platform(); // branding must never take a portal down
        }
        $this->brands->setCurrent($brand);
        View::share('brand', $brand);

        return $next($request);
    }
}
