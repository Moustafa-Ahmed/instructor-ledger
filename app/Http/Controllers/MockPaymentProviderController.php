<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MockPaymentProviderFailedException;
use App\Exceptions\MockPaymentProviderTimeoutException;
use App\Http\Requests\MockPaymentIdempotencyStatusRequest;
use App\Http\Requests\MockPaymentOperationRequest;
use App\Http\Requests\MockPaymentStatusRequest;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MockPaymentProviderController extends Controller
{
    public function charge(MockPaymentOperationRequest $request, MockPaymentProvider $provider): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $provider->chargeMoney(
                $data['idempotency_key'],
                $data['amount_cents'],
                $data['currency'],
                $data['metadata'] ?? [],
            );
        } catch (MockPaymentProviderFailedException $exception) {
            return $this->failedResponse($exception);
        } catch (MockPaymentProviderTimeoutException $exception) {
            return $this->timeoutResponse($exception);
        }

        return response()->json(['data' => $result], $this->statusCodeForOperation($result));
    }

    public function send(MockPaymentOperationRequest $request, MockPaymentProvider $provider): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $provider->sendMoney(
                $data['idempotency_key'],
                $data['amount_cents'],
                $data['currency'],
                $data['metadata'] ?? [],
            );
        } catch (MockPaymentProviderFailedException $exception) {
            return $this->failedResponse($exception);
        } catch (MockPaymentProviderTimeoutException $exception) {
            return $this->timeoutResponse($exception);
        }

        return response()->json(['data' => $result], $this->statusCodeForOperation($result));
    }

    public function status(MockPaymentStatusRequest $request, MockPaymentProvider $provider): JsonResponse
    {
        $result = $provider->status($request->validated('provider_reference'));

        return response()->json(['data' => $result]);
    }

    public function statusByIdempotencyKey(
        MockPaymentIdempotencyStatusRequest $request,
        MockPaymentProvider $provider,
    ): JsonResponse {
        $data = $request->validated();

        $result = $provider->statusByIdempotencyKey(
            $data['operation_type'],
            $data['idempotency_key'],
        );

        return response()->json(['data' => $result]);
    }

    private function statusCodeForOperation(array $result): int
    {
        return match ($result['status']) {
            MockPaymentProvider::STATUS_SUCCEEDED => Response::HTTP_CREATED,
            MockPaymentProvider::STATUS_FAILED => Response::HTTP_UNPROCESSABLE_ENTITY,
            MockPaymentProvider::STATUS_UNKNOWN => Response::HTTP_GATEWAY_TIMEOUT,
        };
    }

    private function failedResponse(MockPaymentProviderFailedException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'data' => [
                'provider_reference' => $exception->operation->provider_reference,
                'type' => $exception->operation->operation_type,
                'status' => $exception->operation->status,
            ],
        ], $exception->statusCode);
    }

    private function timeoutResponse(MockPaymentProviderTimeoutException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], $exception->statusCode);
    }
}
