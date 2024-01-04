<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$tenant_id = "32c7fbc3-2e59-40c0-aeae-6178858dbd38";
$x_api_secret = "P***X";
$tenant_name = "SkogsPortalen";

$headers = [
    'tenant_id' => $tenant_id,
    'x-api-secret' => $x_api_secret,
    'Content-Type' => 'application/json'
];

$url = "https://production.pingpayments.com/payments/api/v1/disbursements";

$client = new Client();

try {
    $response = $client->request('GET', $url, ['headers' => $headers]);
    $body = $response->getBody();
    $data = json_decode($body, true);

    $id = end($data)['id'];

    // Additional API call to get disbursement details
    $disbursementUrl = "https://production.pingpayments.com/payments/api/v1/disbursements/{$id}";
    $disbursementResponse = $client->request('GET', $disbursementUrl, ['headers' => $headers]);
    $disbursementBody = $disbursementResponse->getBody();
    $disbursement = json_decode($disbursementBody, true);

    $settlements = $disbursement["settlements"];

    // Grouping by recipient and reference
    $groupedByRecipientAndReference = [];
    foreach ($settlements as $settlement) {
        $key = $settlement["recipient_name"] . "_" . $settlement["reference"];
        $groupedByRecipientAndReference[$key][] = $settlement;
    }

    $customerPayout = [];
    foreach ($groupedByRecipientAndReference as $key => $values) {
        if (strpos($key, $tenant_name) === false) {
            foreach ($values as $value) {
                $payment_id = $value["payment_id"];
                $payment_order_id = $value["payment_order_id"];

                $paymentUrl = "https://production.pingpayments.com/payments/api/v1/payment_orders/{$payment_order_id}/payments/{$payment_id}";
                $paymentResponse = $client->request('GET', $paymentUrl, ['headers' => $headers]);
                $paymentBody = $paymentResponse->getBody();
                $payment = json_decode($paymentBody, true);

                $orderItems = $payment["order_items"];

                foreach ($orderItems as $item) {
                    $itemVatAmount = $item["amount"] - ($item["amount"] / (1 + ($item["vat_rate"] / 100.0)));
                    $itemAmountExcludingVat = $item["amount"] - $itemVatAmount;
                    $paid = end($payment["status_history"])["occurred_at"];
                    $feeAmount = $item["amount"] - $value["amount"];

                    $customerPayout[] = [
                        "payout_amount" => $value["amount"],
                        "recipient" => $value["recipient_name"],
                        "payment_id" => $payment_id,
                        "payment_order_id" => $payment_order_id,
                        "reference" => $value["reference"],
                        "item_name" => $item["name"],
                        "item_vat_rate" => $item["vat_rate"],
                        "item_amount" => $item["amount"],
                        "item_vat_amount" => $itemVatAmount,
                        "item_amount_excluding_vat" => $itemAmountExcludingVat,
                        "paid" => $paid,
                        "fee_amount" => $feeAmount
                    ];
                }
            }
            break; // Remove this line if you want to process all non tenant groups
        }
    }

    $transformedData = [];
    $sums = ['Pris' => 0, 'Moms' => 0, 'Avgift' => 0, 'Utbetalningsbelopp' => 0];

    foreach ($customerPayout as $item) {
        $datum = substr($item["paid"], 0, 10);
        $namn = $item["item_name"];
        $pris = $item["item_amount"] / 100.0;
        $momssats = intval($item["item_vat_rate"]) . "%";
        $moms = $item["item_vat_amount"] / 100.0;
        $avgift = $item["fee_amount"] / 100.0;
        $utbetalningsbelopp = $item["payout_amount"] / 100.0;

        $transformedData[] = [
            "Datum" => $datum,
            "Namn" => $namn,
            "Pris" => $pris,
            "Momssats" => $momssats,
            "Moms" => $moms,
            "Avgift" => $avgift,
            "Utbetalningsbelopp" => $utbetalningsbelopp
        ];

        $sums['Pris'] += $pris;
        $sums['Moms'] += $moms;
        $sums['Avgift'] += $avgift;
        $sums['Utbetalningsbelopp'] += $utbetalningsbelopp;
    }

    // Assuming the variables such as $disbursement, $customerPayout, and $transformedData are already defined

    $utbetalningsDatum = substr($disbursement["disbursed_at"], 0, 10);
    $utbetalningsReferens = $customerPayout[0]["reference"];
    $betalningsMottagare = $customerPayout[0]["recipient"];

    $totaltUtbetaltBelopp = array_sum(array_column($transformedData, "Utbetalningsbelopp"));

    // Create the HTML for the headers
    $headersHtml = "
        <p><strong>Utbetalningsdatum:</strong> $utbetalningsDatum</p>
        <p><strong>Utbetalningsreferens:</strong> $utbetalningsReferens</p>
        <p><strong>Betalningsmottagare:</strong> $betalningsMottagare</p>
        <p><strong>Totalt utbetalt belopp:</strong> $totaltUtbetaltBelopp</p>
        <br />
        <h3 class=\"text-2xl font-bold mb-4\">Detaljer</h3>
    ";


} catch (GuzzleException $e) {
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Utbetalningsrapport</title>
    <!-- Include Tailwind CSS from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Custom styles if needed */
    </style>
</head>
<body class="m-8">
    <h1 class="text-3xl font-bold mb-4">Utbetalningsrapport</h1>
    <?php echo $headersHtml; ?>
    <table class="min-w-full table-auto border-collapse border border-gray-800">
        <thead class="bg-gray-200">
            <tr>
                <th class="border border-gray-600 px-4 py-2">Datum</th>
                <th class="border border-gray-600 px-4 py-2">Namn</th>
                <th class="border border-gray-600 px-4 py-2">Pris</th>
                <th class="border border-gray-600 px-4 py-2">Momssats</th>
                <th class="border border-gray-600 px-4 py-2">Moms</th>
                <th class="border border-gray-600 px-4 py-2">Avgift</th>
                <th class="border border-gray-600 px-4 py-2">Utbetalningsbelopp</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transformedData as $row): ?>
                <tr>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Datum']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Namn']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Pris']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Momssats']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Moms']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Avgift']); ?></td>
                    <td class="border border-gray-600 px-4 py-2"><?php echo htmlspecialchars($row['Utbetalningsbelopp']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-gray-100">
            <tr>
                <td class="border border-gray-600 px-4 py-2" colspan="2">Total</td>
                <td class="border border-gray-600 px-4 py-2"><?php echo $sums['Pris']; ?></td>
                <td class="border border-gray-600 px-4 py-2"></td>
                <td class="border border-gray-600 px-4 py-2"><?php echo $sums['Moms']; ?></td>
                <td class="border border-gray-600 px-4 py-2"><?php echo $sums['Avgift']; ?></td>
                <td class="border border-gray-600 px-4 py-2"><?php echo $sums['Utbetalningsbelopp']; ?></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>


