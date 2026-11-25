import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Bell } from 'lucide-react'
import { EVENTO_NOTIFICACIONES, notificacionesApi } from '../../api/notificacionesApi'
import { useSesion } from '../../hooks/useSesion'
import './CampanaNotificaciones.css'

const CONSULTA_MS = 60_000

/** Campana del encabezado con la cantidad de notificaciones sin leer. */
export default function CampanaNotificaciones() {
  const { sesion, tienePermiso } = useSesion()
  const { pathname } = useLocation()
  const [noLeidas, setNoLeidas] = useState(0)
  const habilitada = Boolean(sesion) && tienePermiso('notificaciones.ver')

  useEffect(() => {
    if (!habilitada) return undefined
    const consultar = () => notificacionesApi.contador().then((r) => setNoLeidas(r.no_leidas)).catch(() => {})
    const alCambiar = (evento) => setNoLeidas(evento.detail ?? 0)

    consultar()
    const intervalo = window.setInterval(consultar, CONSULTA_MS)
    window.addEventListener(EVENTO_NOTIFICACIONES, alCambiar)
    return () => {
      window.clearInterval(intervalo)
      window.removeEventListener(EVENTO_NOTIFICACIONES, alCambiar)
    }
  }, [habilitada, pathname])

  if (!habilitada) return null

  const etiqueta = noLeidas > 0 ? `Notificaciones: ${noLeidas} sin leer` : 'Notificaciones'

  return (
    <Link to="/notificaciones" className="campana" aria-label={etiqueta} title={etiqueta}>
      <Bell size={20} aria-hidden="true" />
      {noLeidas > 0 && <span className="campana__contador" aria-hidden="true">{noLeidas > 99 ? '99+' : noLeidas}</span>}
    </Link>
  )
}
