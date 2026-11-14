<?php

if (!function_exists('horarioLaboralDisponible')) {
    function horarioLaboralDisponible(?DateTimeImmutable $ahora = null): bool
    {
        $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('America/Caracas'));
        $diaSemana = (int)$ahora->format('N');
        $minutosDelDia = ((int)$ahora->format('G') * 60) + (int)$ahora->format('i');

        return $diaSemana >= 1
            && $diaSemana <= 5
            && $minutosDelDia >= 8 * 60
            && $minutosDelDia < 17 * 60;
    }
}

if (!function_exists('horarioVistaRolDisponible')) {
    function horarioVistaRolDisponible(int $rol, ?DateTimeImmutable $ahora = null): bool
    {
        return !in_array($rol, [3, 6], true) || horarioLaboralDisponible($ahora);
    }
}

if (!function_exists('horarioSolicitudDisponible')) {
    function horarioSolicitudDisponible(int $rol, ?DateTimeImmutable $ahora = null): bool
    {
        return in_array($rol, [4, 5], true) || horarioLaboralDisponible($ahora);
    }
}

if (!function_exists('fechaSolicitudEnHorarioLaboral')) {
    function fechaSolicitudEnHorarioLaboral(string $fecha, ?DateTimeImmutable $ahora = null): bool
    {
        $zona = new DateTimeZone('America/Caracas');
        $solicitud = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fecha, $zona);
        $errores = DateTimeImmutable::getLastErrors();
        if (!$solicitud || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))
            || $solicitud->format('Y-m-d\TH:i') !== $fecha) {
            return false;
        }

        $ahora = $ahora ?? new DateTimeImmutable('now', $zona);
        $diaSemana = (int)$solicitud->format('N');
        $minutosDelDia = ((int)$solicitud->format('G') * 60) + (int)$solicitud->format('i');

        return $solicitud > $ahora
            && $diaSemana >= 1
            && $diaSemana <= 5
            && $minutosDelDia >= 8 * 60
            && $minutosDelDia < 17 * 60;
    }
}

if (!function_exists('mensajeHorarioLaboral')) {
    function mensajeHorarioLaboral(): string
    {
        return 'Esta vista no está disponible fuera del horario laboral (lunes a viernes, de 8:00 a. m. a 5:00 p. m.).';
    }
}
