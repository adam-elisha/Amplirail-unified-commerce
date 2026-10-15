<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ApiException;
use App\Support\Export\StatementDownloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Settlement\Infrastructure\PayoutStatement;
use Symfony\Component\HttpFoundation\Response;

/** Merchants see issued statements only. format=json|csv|pdf|xlsx */
final class StatementApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->page($request, PayoutStatement::query()->where('status', 'issued'), fn (PayoutStatement $s) => $s->toApi()));
    }

    public function show(Request $request, string $id, StatementDownloads $downloads): Response
    {
        $s = PayoutStatement::query()->whereKey($id)->where('status', 'issued')->first() ?? throw ApiException::notFound('payout statement');
        $format = (string) $request->query('format', 'json');
        if ($format === 'json') {
            return response()->json($s->toApi());
        }
        if (! in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
            throw ApiException::invalid('invalid_format', 'format must be json, csv, pdf or xlsx.');
        }

        return $downloads->download($s, $format);
    }
}
