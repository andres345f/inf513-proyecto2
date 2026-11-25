import { ShieldX } from 'lucide-react'
import { useLocation } from 'react-router-dom'
import ComprarComoCliente from '../../components/comercio/ComprarComoCliente'
import PaginaEstado from './PaginaEstado'

export default function SinPermisoPagina() {
  const { pathname } = useLocation()
  return (
    <PaginaEstado icono={ShieldX} titulo="No tienes acceso a esta sección">
      <p>Tu rol activo no tiene permiso para ver esta página. Si tienes otro rol, puedes cambiarlo desde tu menú de usuario.</p>
      {['/carrito', '/checkout'].includes(pathname) && <ComprarComoCliente />}
    </PaginaEstado>
  )
}
