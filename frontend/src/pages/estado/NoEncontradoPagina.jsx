import { MapPinOff } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useSesion } from '../../hooks/useSesion'
import PaginaEstado from './PaginaEstado'

export default function NoEncontradoPagina() {
  const { sesion } = useSesion()

  return (
    <PaginaEstado icono={MapPinOff} titulo="Página no encontrada" sinContador>
      <p>La dirección que buscas no existe o ya no está disponible.</p>
      {!sesion && (
        <p>Si es una sección privada, <Link to="/ingresar">inicia sesión</Link> para verla.</p>
      )}
    </PaginaEstado>
  )
}
