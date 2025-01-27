<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use DateTime;
use Illuminate\Support\Facades\Http;
use App\Models\Ubicacion;

class ExcelController extends Controller
{
    public function index()
    {
        return view('index');
    }

    public function procesar(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('excel_file');
        $spreadsheet = IOFactory::load($file->getRealPath());

        try {
            $sheet = $spreadsheet->getSheetByName('QuoteResume');
            if (!$sheet) {
                throw new \Exception("La hoja 'QuoteResume' no existe.");
            }

            $data = $sheet->toArray(null, true, true, true);

            $df_program = collect($data);
            $programArray = $df_program->toArray();
            $header = array_shift($programArray);

            $df_program = $df_program->map(function ($row) use ($header) {

                $assocRow = array_combine($header, $row);
                $assocRow['Checked'] = $assocRow['Checked'] == 0 ? 'FALSE' : 'TRUE';
                $assocRow['Asignar cupo'] = (int) $assocRow['Asignar cupo'];
                $assocRow['Contrato SAP'] = substr($assocRow['Contrato SAP'], -3);

                return $assocRow;
            })->filter(function ($row) {
                // Filtrar filas según columnas requeridas
                return $row['Parcela'] && $row['Proveedor de servicios de cosecha'] && $row['Fábrica'];
            });

            // Crear DataFrame Formateado
            $df_program_format = collect();
            $j = 0;

            foreach ($df_program as $row) {
                for ($k = 0; $k < $row['Asignar cupo']; $k++) {
                    $j++;
                    $newRow = $row;
                    $newRow['ID'] = $j;
                    $newRow['NCupo'] = (string) ($k + 1);
                    $df_program_format->push($newRow);
                }
            }

            $df_program_format = $df_program_format->map(function ($row) {
                $row['Asignar cupo'] = 1;
                return $row;
            });

            $parcelaHorarios = []; // Para almacenar el último horario de cada parcela
            $df_rutas = $df_program_format->map(function ($row) use (&$parcelaHorarios) {
                $parcela = $row['Parcela'];

                // Si la parcela no existe en el registro, inicializar con 07:00
                if (!isset($parcelaHorarios[$parcela])) {
                    $parcelaHorarios[$parcela] = [
                        'count' => 0, // Contador de apariciones
                        'last_time' => '07:00', // Última hora registrada
                    ];
                }

                // Incrementar el contador de apariciones
                $parcelaHorarios[$parcela]['count']++;

                // Determinar la hora de inicio
                if ($parcelaHorarios[$parcela]['count'] <= 2) {
                    $horaInicio = '07:00'; // Primera y segunda aparición
                } else {
                    // Incrementar una hora desde la última hora registrada
                    $horaActual = DateTime::createFromFormat('H:i', $parcelaHorarios[$parcela]['last_time']);
                    $horaInicio = $horaActual->modify('+1 hour')->format('H:i');
                }

                // Actualizar la última hora registrada para la parcela
                $parcelaHorarios[$parcela]['last_time'] = $horaInicio;

                // Construir la fila con la nueva lógica
                return [
                    'ID' => $row['ID'],
                    'NCupo' => $row['NCupo'],
                    'Nombre de ruta' => "{$row['Prestador servicio de Transporte']}-{$row['Maquina cosechadora']}-{$row['Fábrica']}-{$row['NCupo']}",
                    'Base de origen' => $row['Prestador servicio de Transporte'],
                    'Fecha de inicio (YYYY-MM-DD)' => DateTime::createFromFormat('d-m-Y', $row['Fecha'])->format('Y-m-d'),
                    'Hora de inicio (HH:MM)' => $horaInicio, // Hora calculada
                    'Tiempo de carga (minutos)' => 60,
                    'Tiempo de descarga (minutos)' => 10,
                    'Agricultor' => $row['Agricultor'],
                    'ContratoSAP' => $row['Contrato SAP'],
                    'Parcela' => $row['Parcela']
                ];
            })->toArray();

            $df_viajes = collect();

            $i = 0;

            foreach ($df_rutas as $row) {
                $i++;
                // Determinar el destino según la condición
                if ($row['Agricultor'] === 'SUGAL CHILE LIMITADA') {
                    $destino = "{$row['ContratoSAP']}-{$row['Parcela']}-{$row['Agricultor']}";
                } else {
                    $destino = "{$row['ContratoSAP']}-{$row['Agricultor']}-{$row['Parcela']}";
                }

                $df_viajes->push([
                    'Nombre de ruta' => $row['Nombre de ruta'],
                    'Número de viaje' => $i,
                    'Destino' => $destino,
                    'Tiempo de trayecto (mins)' => '',
                    'Conductor' => '',
                    'Vehículo' => '',
                    'Dirección' => '',
                    'Ciudad' => '',
                    'Estado' => '',
                    'País' => '',
                    'Dirección destino' => '',
                ]);
            }

            $df_actividades = collect();
            $actividades_columns = ['Número de viaje', 'Nombre de actividad', 'Número de actividad', 'Tipo de actividad', 'Duración (mins)','Volumen (m3)','Peso (kg)','Atributo','Nombre contacto','DNI de contacto','Télefono contacto','Email contacto'];
            $z = 0;

            foreach ($df_viajes as $index => $row) {
                if (($index + 1) % 2 == 0) {
                    // Traslado a Planta
                    $z++;
                    $df_actividades->push([
                        'Número de viaje' => $row['Número de viaje'],
                        'Nombre de actividad' => 'Traslado a Planta',
                        'Número de actividad' => $z,
                        'Tipo de actividad' => 'ENTREGA',
                        'Duración (mins)' => 120,
                        'Volumen (m3)' => '',
                        'Peso (kg)' => '',
                        'Atributo' => '',
                        'Nombre contacto' => '',
                        'DNI de contacto' => '',
                        'Télefono contacto' => '',
                        'Email contacto' => '',
                    ]);

                    // Camión Descargado
                    $z++;
                    $df_actividades->push([
                        'Número de viaje' => $row['Número de viaje'],
                        'Nombre de actividad' => 'Camión Descargado',
                        'Número de actividad' => $z,
                        'Tipo de actividad' => 'ENTREGA',
                        'Duración (mins)' => 60,
                        'Volumen (m3)' => '',
                        'Peso (kg)' => '',
                        'Atributo' => '',
                        'Nombre contacto' => '',
                        'DNI de contacto' => '',
                        'Télefono contacto' => '',
                        'Email contacto' => '',
                    ]);
                } else {
                    // Llegada a Parcela
                    $z++;
                    $df_actividades->push([
                        'Número de viaje' => $row['Número de viaje'],
                        'Nombre de actividad' => 'Llegada a Parcela',
                        'Número de actividad' => $z,
                        'Tipo de actividad' => 'RECOGIDA',
                        'Duración (mins)' => 120,
                        'Volumen (m3)' => '',
                        'Peso (kg)' => '',
                        'Atributo' => '',
                        'Nombre contacto' => '',
                        'DNI de contacto' => '',
                        'Télefono contacto' => '',
                        'Email contacto' => '',
                    ]);

                    // Camión Cargado
                    $z++;
                    $df_actividades->push([
                        'Número de viaje' => $row['Número de viaje'],
                        'Nombre de actividad' => 'Camión Cargado',
                        'Número de actividad' => $z,
                        'Tipo de actividad' => 'RECOGIDA',
                        'Duración (mins)' => 60,
                        'Volumen (m3)' => '',
                        'Peso (kg)' => '',
                        'Atributo' => '',
                        'Nombre contacto' => '',
                        'DNI de contacto' => '',
                        'Télefono contacto' => '',
                        'Email contacto' => '',
                    ]);
                }
            }

            // Encabezados de la hoja
            $document_headers = ['Número de actividad', 'Número de documento'];



            $item_headers = ['Número de documento', 'Nombre ítem', 'Código ítem', 'Cantidad planificada', 'Precio unitario'];

            // Convertir la colección en un array y agregar encabezados
            $df_actividades_array = $df_actividades->toArray();
            array_unshift($df_actividades_array, $actividades_columns); // Agregar encabezados como primera fila


            // Convertir la colección en un array y agregar encabezados
            $df_viajes_array = $df_viajes->toArray();
            $viajes_columns = ['Nombre de ruta', 'NumeroDeViaje', 'Destino','Tiempo de trayecto (mins)','Conductor','Vehículo','Dirección','Ciudad','Estado','País','Dirección destino'];
            array_unshift($df_viajes_array, $viajes_columns); // Agregar encabezados como primera fila

            // Eliminar columnas sobrantes
            $df_rutas = array_map(function ($row) {
                unset($row['ID']);
                unset($row['NCupo']);
                unset($row['Agricultor']);
                unset($row['ContratoSAP']);
                unset($row['Parcela']);
                return $row;
            }, $df_rutas);

            // Agregar Encabezados
            array_unshift($df_rutas, [
                'Nombre de ruta',
                'Base de origen',
                'Fecha de inicio (DD-MM-AAAA)',
                'Hora de inicio (HH:MM)',
                'Tiempo de carga (minutos)',
                'Tiempo de descarga (minutos)',
            ]);

            // Crear archivo Excel
            $outputSpreadsheet = new Spreadsheet();
            $writer = new Xlsx($outputSpreadsheet);

            // Hojas
            $sheetRutas = $outputSpreadsheet->getActiveSheet();
            $sheetRutas->setTitle('Rutas');
            $sheetRutas->fromArray($df_rutas, null, 'A1');

            $outputSpreadsheet->createSheet();
            $sheetViajes = $outputSpreadsheet->getSheet(1);
            $sheetViajes->setTitle('Viajes');
            $sheetViajes->fromArray($df_viajes_array, null, 'A1');

            $sheetActividades = $outputSpreadsheet->createSheet();
            $sheetActividades->setTitle('Actividades');
            $sheetActividades->fromArray($df_actividades_array, null, 'A1');

            $sheetDocuments = $outputSpreadsheet->createSheet();
            $sheetDocuments->setTitle('Documentos');
            $sheetDocuments->fromArray([$document_headers], null, 'A1');

            $sheetItems = $outputSpreadsheet->createSheet();
            $sheetItems->setTitle('Items');
            $sheetItems->fromArray([$item_headers], null, 'A1');

            // Guardar archivo procesado temporalmente en memoria
            $tempFile = tempnam(sys_get_temp_dir(), 'excel');
            $writer->save($tempFile);

            // Retornar el archivo como descarga
            return response()->download($tempFile, 'processed_file.xlsx')->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function tranciti_validate_spot()
    {
        $codigoContrato = 177;
        foreach ($this->getUbicaciones() as $ubicacion) {

            if (substr($ubicacion['name'], 0, 3) == $codigoContrato) {
                return $ubicacion['name'];
            }
        }

        return null;

    }

    private function tranciti_register_route()
    {
        #Creo una ruta, luego un viaje y 2 actividades.
    }

    public function getUbicaciones()
    {
        $token = $this->login();

        $url = 'https://api.waypoint.cl/lastmile/api/spot';
        $data = [ ];

        try {
            $response = Http::withHeaders([
                'id-client' => 2611,
                'Authorization' => 'Bearer ' . $token["AccessToken"],
                'Content-Type' => 'application/json',
            ])->get($url);

            if ($response->successful())
            {
                $response = $response->json();
                return $response["data"];
            }

            return response()->json([
                'error' => 'Error en la solicitud',
                'status' => $response->status(),
                'body' => $response->body(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al realizar la solicitud',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function login()
    {
        $url = 'https://auth.waypoint.cl/simplelogin/login'; // Cambia esto por tu endpoint
        $data = [
            'username' => 'felipemoreno',
            'password' => 'Sugal123.',
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, $data);

            if ($response->successful())
            {
                return $response->json();
            }

            return response()->json([
                'error' => 'Error en la solicitud',
                'status' => $response->status(),
                'body' => $response->body(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al realizar la solicitud',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
