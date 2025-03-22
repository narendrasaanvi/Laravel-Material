<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\AppointmentMassage;
use App\Models\Appointment;


class PhonePayController extends Controller
{
    private const PROD_AUTH_URL = "https://api.phonepe.com/apis/identity-manager/v1/oauth/token";
    private const CLIENT_ID = "SU2502102136291876456336";
    private const CLIENT_VERSION = 1;
    private const CLIENT_SECRET = "9d5f81d0-9785-4e30-896c-eda6104d6f13";
    private const GRANT_TYPE = "client_credentials";
    private const PROD_CHECKOUT_URL = "https://api.phonepe.com/apis/pg/checkout/v2/pay";
    private const REDIRECT_URL = "https://ayuthraayurveda.com/phonepay-callback";

    public function index(Request $request)
    {
        $orderId = $request->query('order_id');
        $amount = $request->query('amount');
        $booking = $request->query('booking'); // Ensure booking is retrieved
    
        if (!$orderId || !$amount) {
            return back()->with('error', 'Invalid payment request.');
        }
    
        $authToken = $this->createToken();
        if (!$authToken) {
            return back()->with('error', 'Something went wrong. Please try again.');
        }
    
        // Pass all required parameters
        $paymentData = $this->preparePaymentData($orderId, $amount, $booking);
        $response = $this->sendPaymentRequest($paymentData, $authToken);
    
        if (!empty($response['redirectUrl'])) {
            return $this->redirectToPaymentPage($response['redirectUrl']);
        }
    
        return back()->with('error', 'Payment initiation failed.');
    }


    private function createToken()
    {
        $postData = [
            'client_id' => self::CLIENT_ID,
            'client_version' => self::CLIENT_VERSION,
            'client_secret' => self::CLIENT_SECRET,
            'grant_type' => self::GRANT_TYPE,
        ];

        $response = Http::asForm()->post(self::PROD_AUTH_URL, $postData);

        return $response->successful() ? $response->json()['access_token'] : null;
    }

    private function preparePaymentData($orderId, $amount,$booking)
    {
        return [
            "merchantOrderId" => $orderId,
            "amount" => $amount * 100,
            "expireAfter" => 1200,
            "metaInfo" => [
                "udf1" => "additional-information-1",
                "udf2" => "additional-information-2",
                "udf3" => "additional-information-3",
                "udf4" => "additional-information-4",
                "udf5" => "additional-information-5",
            ],
            "paymentFlow" => [
                "type" => "PG_CHECKOUT",
                "message" => "Myshoprito Pay for service",
                "merchantUrls" => [
                    "redirectUrl" => 'https://ayuthraayurveda.com/phonepay-callback?orderid=' . $orderId . '&booking=' . $booking,
                ],
                "paymentModeConfig" => [
                    "enabledPaymentModes" => [
                        ["type" => "UPI_INTENT"],
                        ["type" => "UPI_COLLECT"],
                        ["type" => "UPI_QR"],
                        ["type" => "NET_BANKING"],
                        [
                            "type" => "CARD",
                            "cardTypes" => [
                                "DEBIT_CARD",
                                "CREDIT_CARD"
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function sendPaymentRequest($paymentData, $authToken)
    {
        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $authToken,
            'Content-Type' => 'application/json',
        ])->post(self::PROD_CHECKOUT_URL, $paymentData);

        return $response->successful() ? $response->json() : [];
    }

    private function redirectToPaymentPage($url)
    {
        echo "<script>window.location.href = '" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "';</script>";
        exit();
    }


    public function handleCallback(Request $request)
    {
        try {
            $orderId = $request->query('orderid');
            $bookingId = $request->query('booking');
 
    
            if (!$orderId) {
                return response()->json(['error' => 'Order ID is missing'], 400);
            }
    
            $authToken = $this->createToken();
            if (!$authToken) {
                return response()->json(['error' => 'Failed to get authorization token.'], 400);
            }
    
            $orderStatusResponse = $this->getOrderStatus($orderId, $authToken);
    
            if (!empty($orderStatusResponse['state']) && $orderStatusResponse['state'] === 'COMPLETED') {
                $transactionId = $orderStatusResponse['paymentDetails'][0]['transactionId'] ?? null;
                $transactionOrderId = $orderStatusResponse['orderId'] ?? null;
 
                // Ensure bookingType is properly handled
                if ($bookingId === 'Appointments') {
                    Appointment::where('order_id', $orderId)->update([
                        'transaction_id' => $transactionId,
                        'transaction_order_no' => $transactionOrderId,
                        'payment_status' => 'Completed',
                    ]);
                } elseif ($bookingId === 'Massage') {
                    AppointmentMassage::where('order_id', $orderId)->update([
                        'transaction_id' => $transactionId,
                        'transaction_order_no' => $transactionOrderId,
                        'payment_status' => 'Completed',
                    ]);
                }
    
                return view('frontend.appointment.thanks', compact('transactionId', 'transactionOrderId', 'bookingId', 'orderId'));
            }
    
            return back()->with('error', 'Payment was not successful.');
        } catch (\Throwable $e) {
            Log::error('Error during callback handling: ' . $e->getMessage());
            return response()->json(['error' => 'An internal error occurred.', 'code' => $e->getCode()], 500);
        }
    }
    
    



    private function getOrderStatus($orderId, $authToken)
    {
        $url = "https://api.phonepe.com/apis/pg/checkout/v2/order/{$orderId}/status";

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $authToken,
            'Content-Type' => 'application/json',
        ])->get($url);

        return $response->successful() ? $response->json() : ['error' => 'Failed to retrieve order status.'];
    }
}
