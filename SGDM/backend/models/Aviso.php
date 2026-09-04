<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de Avisos: novedades del torneo que funcionan como notificaciones
 * para los participantes (cambios de horario, fixture publicado, resultados,
 * inscripciones abiertas y mensajes del organizador).
 */
class Aviso extends Model {

    protected string $table = 'avisos';

    /**
     * Publica un aviso. $torneoId null = aviso global del sistema.
     *
     * @return int ID del aviso.
     */
    public function publicar(?int $torneoId, ?int $autorId, string $tipo, string $titulo, ?string $cuerpo = null): int {
        return $this->insert([
            'torneo_id' => $torneoId,
            'autor_id'  => $autorId,
            'tipo'      => $tipo,
            'titulo'    => $titulo,
            'cuerpo'    => $cuerpo,
        ]);
    }

    /**
     * Avisos de un torneo, del más reciente al más antiguo.
     *
     * @return array
     */
    public function listarPorTorneo(int $torneoId, int $limite = 20): array {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.tipo, a.titulo, a.cuerpo, a.created_at,
                    TRIM(CONCAT(u.nombre, \' \', COALESCE(u.apellido, \'\'))) AS autor
               FROM avisos a
               LEFT JOIN usuarios u ON u.id = a.autor_id
              WHERE a.torneo_id = ?
              ORDER BY a.created_at DESC
              LIMIT ' . (int) $limite
        );
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }
}
