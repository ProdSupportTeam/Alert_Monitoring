<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DecryptController extends Controller
{
public function decryptCsv()
{
    $inputPath = storage_path('app/query_result_2026-04-30T13_54_22.384195075Z.csv'); // 👈 change path
    $outputFileName = 'decrypted_output.csv';

    if (!file_exists($inputPath)) {
        return "File not found";
    }

    $rows = array_map('str_getcsv', file($inputPath));

    if (empty($rows)) {
        return "Empty file";
    }

    $header = array_shift($rows);

    // 👉 Columns to decrypt (index based)
    $columnsToDecrypt = [6, 7]; // mobile, email (update as needed)

    $finalData[] = $header;

    foreach ($rows as $row) {

        foreach ($columnsToDecrypt as $colIndex) {

            if (!empty($row[$colIndex])) {

                $result = $this->aesCipher($row[$colIndex], 'decrypt', 1);

                if (isset($result['status']) && $result['status']) {
                    $row[$colIndex] = $result['data'];
                }
            }
        }

        $finalData[] = $row;
    }

    $filePath = $this->generateCsv($finalData, $outputFileName);

    return response()->download($filePath)->deleteFileAfterSend(true);
}
public function generateCsv($data, $fileName)
{
    $filePath = storage_path('app/' . $fileName);

    $handle = fopen($filePath, 'w');

    foreach ($data as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $filePath;
}
private function aesCipher($data = '', $type = 'decrypt')
{
    try {

        if (empty($data)) {
            return ['status' => false, 'data' => 'EMPTY'];
        }

        $key = $this->getEncryptionKey();

        switch ($type) {

            case 'encrypt':
                $output = $this->encrypt3DES($data, $key);
                break;

            case 'decrypt':
                $output = $this->decrypt3DES($data, $key);
                break;

            default:
                return ['status' => false, 'data' => 'INVALID_TYPE'];
        }

        return $output !== false
            ? ['status' => true, 'data' => $output]
            : ['status' => false, 'data' => 'DECRYPT_FAILED'];

    } catch (\Exception $e) {
        return ['status' => false, 'data' => $e->getMessage()];
    }
}
private function getEncryptionKey()
{
    $secret = env('DECRYPT_KEY'); // 👈 put original key here

    $key = md5(utf8_encode($secret), true);
    $key .= substr($key, 0, 8);

    return $key; // 24 bytes for 3DES
}
private function decrypt3DES($encrypted)
{
    $key = $this->getEncryptionKey();

    $data = base64_decode($encrypted);

    $decrypted = openssl_decrypt(
        $data,
        'des-ede3', // 🔥 3DES ECB
        $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
    );

    if ($decrypted === false) {
        return false;
    }

    // Remove padding manually (same as your old code)
    $pad = ord($decrypted[strlen($decrypted) - 1]);

    return substr($decrypted, 0, -$pad);
}
private function encrypt3DES($data)
{
    $key = $this->getEncryptionKey();

    // PKCS5 padding
    $block = 8;
    $pad = $block - (strlen($data) % $block);
    $data .= str_repeat(chr($pad), $pad);

    $encrypted = openssl_encrypt(
        $data,
        'des-ede3',
        $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
    );

    return base64_encode($encrypted);
}
private function isReadable($text)
{
    if ($text === false || empty($text)) return false;

    // check if readable (email / number / text)
    return preg_match('/^[\w@\.\-\+ ]+$/', $text);
}
}
