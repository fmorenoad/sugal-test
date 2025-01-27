<?php

namespace App\Models;

class Ubicacion
{
    public $id;
    public $type;
    public $name;
    public $description;
    public $category;
    public $comments;
    public $address;

    /**
     * Constructor para inicializar los datos de una ubicación.
     */
    public function __construct($data)
    {
        $this->id = $data['id'];
        $this->type = $data['type'];
        $this->name = $data['name'];
        $this->description = $data['description'];
        $this->category = $data['category'];
        $this->comments = $data['comments'];
        $this->address = $data['address'];
    }

    /**
     * Método estático para buscar por los primeros 3 dígitos del nombre.
     *
     * @param array $ubicaciones Lista de ubicaciones.
     * @param int $numero Número de 3 dígitos a buscar.
     * @return string|null Nombre completo si se encuentra, null si no existe.
     */
    public static function buscarPorNumero(array $ubicaciones, int $numero)
    {
        foreach ($ubicaciones as $ubicacion) {
            if (strpos($ubicacion->name, (string) $numero) === 0) {
                return $ubicacion->name;
            }
        }

        return null;
    }
}
