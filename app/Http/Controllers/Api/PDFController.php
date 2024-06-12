<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Validator;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\TesseractOCR;
use setasign\Fpdi\Fpdi;
use Spatie\PdfToImage\Pdf;
use Illuminate\Support\Facades\File;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\ImageContext;
use App\Services\OpenAIService;
use OpenAI\Laravel\Facades\OpenAI;

use function Laravel\Prompts\text;

class PDFController extends Controller
{


    public function extractTextFromPdf(Request $request)
    {
        // Guardar el PDF
        $rutaPDF = $request->file('pdf_file')->store('pdfs');


        // Convertir todas las páginas del PDF en imágenes
        $pdf = new Pdf(storage_path('app/' . $rutaPDF));
        //$pdf->setPageRange(1, 5);
        //dd($pdf);
        // Obtener el número total de páginas del PDF
        $numeroPaginas =  $pdf->getNumberOfPages();

        $numerodepaginas=($request->fin<=$numeroPaginas)?$request->fin:$numeroPaginas;

        $namefiles = pathinfo($rutaPDF, PATHINFO_FILENAME);
        // Ruta donde guardar las imágenes
        $rutaImagenes = public_path('imagenes/'.$namefiles.'/');

        // Verificar si el directorio existe, si no, crearlo
        if (!File::exists($rutaImagenes)) {
            File::makeDirectory($rutaImagenes, 0777, true, true);
        }


        $errores = [];
        $respueta = "se etrajeron todas las imagenes";
        // Iterar sobre cada página del PDF
        for ($pagina = $request->inicio; $pagina <= $numerodepaginas; $pagina++) {
            // Guardar la página actual como imagen
            try {
                $pdf->setPage($pagina)->saveImage($rutaImagenes . 'pagina_' . $pagina . '.jpg');
            } catch (\Throwable $th) {
                //throw $th;
                //$errores [] = $th;
                array_push($errores, $th);
                //dd([$th, $request]);
            }


        }

        // Inicializar el cliente de Cloud Vision
        $imageAnnotator = new ImageAnnotatorClient([
            'credentials' => [
                "type"=> "service_account",
                "project_id"=> "pricetrack-390021",
                "private_key_id"=> "c5d4c18fb8c12c9a4d264cfe96a9e6502ba960f1",
                "private_key"=> "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDIJkQflAY6fqPX\nM2cwXIbwV9l0ZPX9PoFeCbx9tKt8G2CPA7oDTBqgCZhqegLwNi0JwmIxI39yj3ZK\n1annvXxD0tSjUFV0IhzZYEwMLtBHNT/Yciewcx4LhkX3ziQBtY8VkNoIbOvKAoQT\nrQKuyq15eBIKDhSBvV6HZ8lcNrN/d0A9o1U4IGOb590qPxOdo039IBX5MQWUI9O0\niK0I7kchCLx/DNL9V5Mc7755HVWyDRl9iRAagY7qjqHmHBxHyU7uDX03goNbHan/\nkLTI/lXmwyrrC1C/wgRsFc1/0Zf6lDsX3XvepmRY0GIBOlTNtIl+doseKOydmQa0\nibyCBifDAgMBAAECggEAJMtkGqoLuxUs6ZL5lVptNCHySAOHdVbYUYFYtYNRQy+F\nQMVenNyQyHr7Ghf0ElTjUrf5dS8hbt4Q0REWexPKlG1dyegqzX637v5U/Khegbi4\nVAIoabe//j5g/n1XATlvZHeMnZ/oeOqdfUCBDcEUww/fwRl1i6RUjG/4BIvJ09S0\n2WrCJyEYwEk767hu+eF0ht0HcJVqUdRN5VhdGGd02CvZeNUqG3WPCOOg/UGwzvxS\nvZa6ZfrYXaA6DvOlqMh+RZ5It3OKcwYSLODNqN6xsJdtijNoIb60us3jJzENvcMa\nG/DHZkhOmh530bgImyE1vjVyj/CN7AgHqhb2lmhqAQKBgQD3yOuLuoUPubyOlnAS\nVsBklE4imt8yAlGdW9Np3VFUkZK/EV1lZIyZTf0hPz4tOS/pj/GtehLUmJTIpA+e\ne4rXsac+rLHatqfbfvc0Eo+nEnt98ACBmIROLGOzlM6DgKxmHxJFRZacWwsE73Q5\nKaHYBuqf9rbDk7M4B5Lh6y4P0QKBgQDOyQonn4j4vb/0uBffOvpnXONTSqsB/fZB\nXFCUEVahp5WSk8qQQslkl9C/NGAMlHZ6gvp83+Vp6A/InePvw8ivXyZdW6Nm41ge\nVVwValsUfxfHdsW+uZ/Tn9pC8mbtZ4dzwoG1Qgjgwf91NB1DAreP0mvx1s5Wqusr\nGncCbAdXUwKBgQCl3JN3U+JP9Xd3RtI8JF/is7ddyKeQ1SaGm/n3ilMvtcYyKdCH\n13eaAy9m+uuG4BnnURholCdYsc4eRFvELVRyL5QRCw5+pffUoLee3rHUFzYcxfPA\nzDP8FBClG/3k3tQIA9J6FivL+9Fze0okHW8dqPuTGlWasxqrbb5vhbqukQKBgBRZ\nIh+uCjt36Ji7ONYlpphfQptioJtMk1vxKpi3cA/uPsCyvF8fw1ObwNXf4Ie8YEBD\n/UQmgBvA0zTJnLFuUaQ4N70+FEE+o+AwRCRzV80XiI5/OIxBFeIsO70Uv14jLugM\nPtlISzlavbmZzDtY3BlR+n9MxPcwUH3oV8esO7izAoGBAMw6zsPIxbUaCfJMBuQs\nq7hY1c0NLsWTWi3JVjUoVg9choo9RxavP+lYbqM4TFdjxIXuxfOKkAiCxqOcEWWs\nQy/Ib7casoWlwOIPcpZKmoXrYJkN43UgsTW+F2bSkDpvOsLP5VHF9nZliwqPDTp0\nJlnlHomZHP3CwNdvrDRY0Gdc\n-----END PRIVATE KEY-----\n",
                "client_email"=> "firebase-adminsdk-cdjhz@pricetrack-390021.iam.gserviceaccount.com",
                "client_id"=> "102324187258901042462",
                "auth_uri"=> "https://accounts.google.com/o/oauth2/auth",
                "token_uri"=> "https://oauth2.googleapis.com/token",
                "auth_provider_x509_cert_url"=> "https://www.googleapis.com/oauth2/v1/certs",
                "client_x509_cert_url"=> "https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-cdjhz%40pricetrack-390021.iam.gserviceaccount.com",
                "universe_domain"=> "googleapis.com"
            ],
        ]);

        // Array para almacenar los resultados
        $resultados = [];

        // Recorrer todas las imágenes en la carpeta

        //$archivos = scandir('imagenes/'.$namefiles);
        $resultadoConversion = [];
        $textoResultadoVision = "";
        //foreach ($archivos as $archivo) {
        for ($pagina = $request->inicio; $pagina <= $numerodepaginas; $pagina++){

            // Ignorar archivos que no son imágenes

            try {
                //code...
                $rutaArchivo = 'imagenes/'.$namefiles . '/' . 'pagina_' . $pagina . '.jpg';
            $imagen = file_get_contents(public_path($rutaArchivo));


            // Construye un objeto AnnotateImageRequest
            $annotateImageRequest = (new AnnotateImageRequest())
                ->setImage((new \Google\Cloud\Vision\V1\Image())->setContent($imagen))
                ->setFeatures([
                    (new Feature())->setType(Feature\Type::DOCUMENT_TEXT_DETECTION)->setModel('builtin/latest')
                ]);

            // Envía la solicitud a la API de Cloud Vision
            $response = $imageAnnotator->batchAnnotateImages([$annotateImageRequest]);

            // Extrae la respuesta JSON
            $json = $response->serializeToJsonString();
            $json = json_decode($json, true);

            $textoEtraido = $json['responses'][0]['textAnnotations'][0]['description'];




            // Cierra el cliente de Cloud Vision
            $imageAnnotator->close();

            $textoResultadoVision = $textoResultadoVision . $textoEtraido;


            } catch (\Throwable $th) {
                //$errores [] = $th;
                array_push($errores, $th);
                //dd($th);
            }



        }


        //$texto = $respueta;
        $data[] = [];

        $textoEnviar = " buscar $request->identificadores y devuélveme en enformato json del siguiente texto: ".$textoResultadoVision;

        $result = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $textoEnviar],
            ],
        ]);

        $output = $result->choices[0]->message->content;


        /*$rutaImagenes = public_path('imagenes');

        // Obtener todas las subcarpetas (carpetas de cada PDF)
        $subcarpetas = File::directories($rutaImagenes);

        // Eliminar cada subcarpeta (carpeta de cada PDF)
        foreach ($subcarpetas as $subcarpeta) {
            File::deleteDirectory($subcarpeta);
        }

        // Obtener la lista de archivos PDF en la carpeta 'pdfs' dentro de 'storage'
        $archivos = Storage::files('pdfs');

        // Iterar sobre cada archivo y eliminarlo
        foreach ($archivos as $archivo) {
            Storage::delete($archivo);
        }

        $archivosPublic = Storage::disk('public')->files('pdfs');

        foreach ($archivosPublic as $archivo) {
            Storage::disk('public')->delete($archivo);
        }*/
        /*if (count($errores) > 0) {
            return $errores;
        } else {
            return json_decode($output);
        }*/

        return json_decode($output);
        return response()->json(['data' => $resultados,'mensaje' => $respueta]);
    }

    public function extractTextFromPdfVisionWeb(Request $request)
    {
        // Guardar el PDF
        $rutaPDF = $request->file('pdf_file')->store('pdfs');


        // Convertir todas las páginas del PDF en imágenes
        $pdf = new Pdf(storage_path('app/' . $rutaPDF));
        //$pdf->setPageRange(1, 5);
        //dd($pdf);
        // Obtener el número total de páginas del PDF
        $numeroPaginas =  $pdf->getNumberOfPages();

        $numerodepaginas=($request->fin<=$numeroPaginas)?$request->fin:$numeroPaginas;

        $namefiles = pathinfo($rutaPDF, PATHINFO_FILENAME);
        // Ruta donde guardar las imágenes
        $rutaImagenes = public_path('imagenes/'.$namefiles.'/');

        // Verificar si el directorio existe, si no, crearlo
        if (!File::exists($rutaImagenes)) {
            File::makeDirectory($rutaImagenes, 0777, true, true);
        }


        $errores = [];
        $respueta = "se etrajeron todas las imagenes";
        // Iterar sobre cada página del PDF
        for ($pagina = $request->inicio; $pagina <= $numerodepaginas; $pagina++) {
            // Guardar la página actual como imagen
            try {
                $pdf->setPage($pagina)->saveImage($rutaImagenes . 'pagina_' . $pagina . '.jpg');
            } catch (\Throwable $th) {
                //throw $th;
                //$errores [] = $th;
                array_push($errores, $th);
                //dd([$th, $request]);
            }


        }

        // Inicializar el cliente de Cloud Vision
        $imageAnnotator = new ImageAnnotatorClient([
            'credentials' => [
                "type"=> "service_account",
                "project_id"=> "pricetrack-390021",
                "private_key_id"=> "c5d4c18fb8c12c9a4d264cfe96a9e6502ba960f1",
                "private_key"=> "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDIJkQflAY6fqPX\nM2cwXIbwV9l0ZPX9PoFeCbx9tKt8G2CPA7oDTBqgCZhqegLwNi0JwmIxI39yj3ZK\n1annvXxD0tSjUFV0IhzZYEwMLtBHNT/Yciewcx4LhkX3ziQBtY8VkNoIbOvKAoQT\nrQKuyq15eBIKDhSBvV6HZ8lcNrN/d0A9o1U4IGOb590qPxOdo039IBX5MQWUI9O0\niK0I7kchCLx/DNL9V5Mc7755HVWyDRl9iRAagY7qjqHmHBxHyU7uDX03goNbHan/\nkLTI/lXmwyrrC1C/wgRsFc1/0Zf6lDsX3XvepmRY0GIBOlTNtIl+doseKOydmQa0\nibyCBifDAgMBAAECggEAJMtkGqoLuxUs6ZL5lVptNCHySAOHdVbYUYFYtYNRQy+F\nQMVenNyQyHr7Ghf0ElTjUrf5dS8hbt4Q0REWexPKlG1dyegqzX637v5U/Khegbi4\nVAIoabe//j5g/n1XATlvZHeMnZ/oeOqdfUCBDcEUww/fwRl1i6RUjG/4BIvJ09S0\n2WrCJyEYwEk767hu+eF0ht0HcJVqUdRN5VhdGGd02CvZeNUqG3WPCOOg/UGwzvxS\nvZa6ZfrYXaA6DvOlqMh+RZ5It3OKcwYSLODNqN6xsJdtijNoIb60us3jJzENvcMa\nG/DHZkhOmh530bgImyE1vjVyj/CN7AgHqhb2lmhqAQKBgQD3yOuLuoUPubyOlnAS\nVsBklE4imt8yAlGdW9Np3VFUkZK/EV1lZIyZTf0hPz4tOS/pj/GtehLUmJTIpA+e\ne4rXsac+rLHatqfbfvc0Eo+nEnt98ACBmIROLGOzlM6DgKxmHxJFRZacWwsE73Q5\nKaHYBuqf9rbDk7M4B5Lh6y4P0QKBgQDOyQonn4j4vb/0uBffOvpnXONTSqsB/fZB\nXFCUEVahp5WSk8qQQslkl9C/NGAMlHZ6gvp83+Vp6A/InePvw8ivXyZdW6Nm41ge\nVVwValsUfxfHdsW+uZ/Tn9pC8mbtZ4dzwoG1Qgjgwf91NB1DAreP0mvx1s5Wqusr\nGncCbAdXUwKBgQCl3JN3U+JP9Xd3RtI8JF/is7ddyKeQ1SaGm/n3ilMvtcYyKdCH\n13eaAy9m+uuG4BnnURholCdYsc4eRFvELVRyL5QRCw5+pffUoLee3rHUFzYcxfPA\nzDP8FBClG/3k3tQIA9J6FivL+9Fze0okHW8dqPuTGlWasxqrbb5vhbqukQKBgBRZ\nIh+uCjt36Ji7ONYlpphfQptioJtMk1vxKpi3cA/uPsCyvF8fw1ObwNXf4Ie8YEBD\n/UQmgBvA0zTJnLFuUaQ4N70+FEE+o+AwRCRzV80XiI5/OIxBFeIsO70Uv14jLugM\nPtlISzlavbmZzDtY3BlR+n9MxPcwUH3oV8esO7izAoGBAMw6zsPIxbUaCfJMBuQs\nq7hY1c0NLsWTWi3JVjUoVg9choo9RxavP+lYbqM4TFdjxIXuxfOKkAiCxqOcEWWs\nQy/Ib7casoWlwOIPcpZKmoXrYJkN43UgsTW+F2bSkDpvOsLP5VHF9nZliwqPDTp0\nJlnlHomZHP3CwNdvrDRY0Gdc\n-----END PRIVATE KEY-----\n",
                "client_email"=> "firebase-adminsdk-cdjhz@pricetrack-390021.iam.gserviceaccount.com",
                "client_id"=> "102324187258901042462",
                "auth_uri"=> "https://accounts.google.com/o/oauth2/auth",
                "token_uri"=> "https://oauth2.googleapis.com/token",
                "auth_provider_x509_cert_url"=> "https://www.googleapis.com/oauth2/v1/certs",
                "client_x509_cert_url"=> "https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-cdjhz%40pricetrack-390021.iam.gserviceaccount.com",
                "universe_domain"=> "googleapis.com"
            ],
        ]);

        // Array para almacenar los resultados
        $resultados = [];

        // Recorrer todas las imágenes en la carpeta

        //$archivos = scandir('imagenes/'.$namefiles);
        $resultadoConversion = [];
        $textoResultadoVision = "";
        //foreach ($archivos as $archivo) {
        for ($pagina = $request->inicio; $pagina <= $numerodepaginas; $pagina++){

            // Ignorar archivos que no son imágenes

            try {
                //code...
                $rutaArchivo = 'imagenes/'.$namefiles . '/' . 'pagina_' . $pagina . '.jpg';
            $imagen = file_get_contents(public_path($rutaArchivo));


            // Construye un objeto AnnotateImageRequest
            $annotateImageRequest = (new AnnotateImageRequest())
                ->setImage((new \Google\Cloud\Vision\V1\Image())->setContent($imagen))
                ->setFeatures([
                    (new Feature())->setType(Feature\Type::DOCUMENT_TEXT_DETECTION)->setModel('builtin/latest')
                ]);

            // Envía la solicitud a la API de Cloud Vision
            $response = $imageAnnotator->batchAnnotateImages([$annotateImageRequest]);

            // Extrae la respuesta JSON
            $json = $response->serializeToJsonString();
            $json = json_decode($json, true);

            $textoEtraido = $json['responses'][0]['textAnnotations'][0]['description'];




            // Cierra el cliente de Cloud Vision
            $imageAnnotator->close();

            $textoResultadoVision = $textoResultadoVision . $textoEtraido;


            } catch (\Throwable $th) {
                //$errores [] = $th;
                array_push($errores, $th);
                //dd($th);
            }



        }


        //$texto = $respueta;
        $data[] = [];

        $textoEnviar = " buscar $request->identificadores y devuélveme en enformato json del siguiente texto: ".$textoResultadoVision;
        dd($textoEnviar);
        $result = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $textoEnviar],
            ],
        ]);

        $output = $result->choices[0]->message->content;


        /*$rutaImagenes = public_path('imagenes');

        // Obtener todas las subcarpetas (carpetas de cada PDF)
        $subcarpetas = File::directories($rutaImagenes);

        // Eliminar cada subcarpeta (carpeta de cada PDF)
        foreach ($subcarpetas as $subcarpeta) {
            File::deleteDirectory($subcarpeta);
        }

        // Obtener la lista de archivos PDF en la carpeta 'pdfs' dentro de 'storage'
        $archivos = Storage::files('pdfs');

        // Iterar sobre cada archivo y eliminarlo
        foreach ($archivos as $archivo) {
            Storage::delete($archivo);
        }

        $archivosPublic = Storage::disk('public')->files('pdfs');

        foreach ($archivosPublic as $archivo) {
            Storage::disk('public')->delete($archivo);
        }*/
        /*if (count($errores) > 0) {
            return $errores;
        } else {
            return json_decode($output);
        }*/
        dd($output);
        return json_decode($output);
        return response()->json(['data' => $resultados,'mensaje' => $respueta]);
    }
}
