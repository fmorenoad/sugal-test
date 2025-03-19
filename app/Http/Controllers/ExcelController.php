<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use DateTime;
use Illuminate\Support\Facades\Http;

class ExcelController extends Controller
{
    public $ubicaciones = NULL;

    public function index()
    {
        return view('index');
    }

    public function procesar(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls',
        ]);

        if (!$request->hasFile('excel_file')) {
            return response()->json(['error' => 'No se ha subido ningún archivo.'], 400);
        }
        $file = $request->file('excel_file');
        
        if (!$file->isValid()) {
            return response()->json(['error' => 'El archivo subido no es válido.'], 400);
        }
        
        $file = $request->file('excel_file');
        $path = $file->getPathname();
        $spreadsheet = IOFactory::load($path);

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
                return $row['Parcela'] && $row['Proveedor de servicios de cosecha'] && $row['Fábrica'];
            });
            
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

                $startTime = DateTime::createFromFormat('d-m-Y', $row['Fecha']);
                $startTime->modify($horaInicio);
                $startTime = $startTime->getTimestamp();

                $transportista = $this->tranciti_validate_spot_transportista($row['Prestador servicio de Transporte']);

                return [
                    "ContratoSAP" => $row['Contrato SAP'],
                    "name" => "{$row['Parcela']}-{$row['Maquina cosechadora']}-{$row['Fábrica']}-{$row['NCupo']}",
                    "loadTime" => 60*60,
                    "unloadTime" => 10*60,

                    "origin" => [
                        "name" => $transportista['name'],
                        "id" => $transportista['id'],
                    ],

                    "startDate" => $startTime * 1000

                ];
            })->toArray();
            
            $transportistas_no_registrados = collect($df_rutas)->whereNull('origin.name')->values()->toArray();

            $transportistas_no_registrados = collect($transportistas_no_registrados)->map(function ($item) {
                return strtok($item['name'], '-');
            })->unique()->values()->toArray();

            if (!empty($transportistas_no_registrados)) {
                return response()->view('welcome-sugal', [
                    'status' => 'Se encontraron transportistas no registrados:',
                    'transportistas' => $transportistas_no_registrados
                ], 200);    
            }

            $df_viajes = collect();

            $i = 0;

            foreach ($df_rutas as $key => $row) {
                $i++;
                $destino =  $this->tranciti_validate_spot($row['ContratoSAP']);
                $planta = $this->tranciti_validate_spot_transportista($row['Fabrica']);

                $df_rutas[$key]['trips'] =
                [
                    [
                     "name"=> $destino['name'],
                     "destination"=> [
                        "id"=> $destino['id'],
                        "name"=> $destino['name'],
                    ],

                    "activities" => [
                        [
                            "type" => "COLLECTION",
                            "name" => "Camión Cargado",
                            "description" => "Camión ya esta con carga y se prepara para salir de parcela",
                            "volume" => 0,
                            "weight" => 0,
                            "duration" => 60*60,
                            "customerName" => NULL,
                            "customerLegalNumber" => NULL,
                            "customerPhone" => NULL,
                            "customerEmail" => NULL,
                            "documents" => [],
                        ],
                        [
                            "type" => "DELIVERY",
                            "name" => "Traslado a Planta",
                            "description" => "Camión ha salido de parcela y esta en transito a Planta",
                            "volume" => 0,
                            "weight" => 0,
                            "duration" => 120*60,
                            "customerName" => NULL,
                            "customerLegalNumber" => NULL,
                            "customerPhone" => NULL,
                            "customerEmail" => NULL,
                            "documents" => [],
                        ],
                        [
                            "type" => "DELIVERY",
                            "name" => "Camión Descargado",
                            "description" => "Camión fue descargado en Planta",
                            "volume" => 0,
                            "weight" => 0,
                            "duration" => 60*60,
                            "customerName" => NULL,
                            "customerLegalNumber" => NULL,
                            "customerPhone" => NULL,
                            "customerEmail" => NULL,
                            "documents" => [],
                        ],
                    ]
                ]
                    ];
            }

            foreach ($df_rutas as $key => $item) {
                unset($df_rutas[$key]['ContratoSAP']);
            }

            $this->tranciti_register_route($df_rutas);

            return response()->view('welcome-sugal', [
                'status' => 'Rutas cargadas con exito en tranciti!',
                'transportistas' => $transportistas_no_registrados
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function tranciti_validate_spot($codigoContrato)
    {
        foreach ($this->getUbicaciones() as $ubicacion) {

            if (substr($ubicacion['name'], 0, 3) == $codigoContrato) {
                return ['name' => $ubicacion['name'], 'id' => $ubicacion['id']];
            }
        }
       return ['name' => NULL, 'id' => NULL];
    }

    public function tranciti_validate_spot_transportista($transportista)
    {
        $ubicacion = collect($this->getUbicaciones())->firstWhere('name', $transportista);

        if ($ubicacion) {
            return ['name' => $ubicacion['name'], 'id' => $ubicacion['id']];
        } else {
            return ['name' => null, 'id' => null];
        }        
    }

    private function tranciti_register_route($df_rutas)
    {
        $apiKEY = config('app.tranciti.api-key');

        $token = $this->login();

        $url = config('app.tranciti.url');
        $data = $df_rutas;

        $response = Http::withHeaders([
            'id-client' => config('app.tranciti.id-client'),
            'Authorization' => 'Bearer ' . $token["AccessToken"],
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKEY,
        ])->post($url, $data);

        try {
            $response = Http::withHeaders([
                'id-client' => config('app.tranciti.id-client'),
                'Authorization' => 'Bearer ' . $token["AccessToken"],
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKEY,
            ])->post($url, [$data]);
            
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

    public function getUbicaciones()
    {
        if($this->ubicaciones == NULL)
        {
            $token = $this->login();

            $url = config('app.tranciti.url') . '/spot';
            $data = [ ];

            try {
                $response = Http::withHeaders([
                    'id-client' => config('app.tranciti.id-client'),
                    'Authorization' => 'Bearer ' . $token["AccessToken"],
                    'Content-Type' => 'application/json',
                ])->get($url);

                if ($response->successful())
                {
                    $response = $response->json();
                    $this->ubicaciones = $response["data"];
                    return $this->ubicaciones;
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

        } else
        {
            return $this->ubicaciones;
        }

    }

    public function login()
    {
        $url = 'https://auth.waypoint.cl/simplelogin/login'; // Cambia esto por tu endpoint
        $data = [
            'username' => config('app.tranciti.username'),
            'password' => config('app.tranciti.password'),
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
