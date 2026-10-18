<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Services\SchemaStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SetupController extends Controller
{
    public function show(SchemaStatus $schema): View|RedirectResponse
    {
        if ($schema->isCurrent()) {
            return redirect()->route('console.dashboard');
        }

        return view('console.setup', [
            'pending' => $schema->pending(),
            'report' => [],
            'current' => false,
        ]);
    }

    public function run(Request $request, SchemaStatus $schema): RedirectResponse|View|StreamedResponse
    {
        if ($schema->isCurrent()) {
            return redirect()->route('console.dashboard');
        }

        if (str_contains((string) $request->header('Accept'), 'application/x-ndjson')) {
            $write = function (array $step): void {
                echo json_encode($step)."\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            return response()->stream(function () use ($schema, $write): void {
                $schema->migrate($write);

                $write([
                    'name' => '_done',
                    'status' => $schema->isCurrent() ? 'done' : 'blocked',
                    'detail' => null,
                ]);
            }, Response::HTTP_OK, [
                'Content-Type' => 'application/x-ndjson',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $report = $schema->migrate();

        return view('console.setup', [
            'pending' => $schema->pending(),
            'report' => $report,
            'current' => $schema->isCurrent(),
        ]);
    }
}
