import { Link, useLocation } from 'react-router-dom'
import { MoreHorizontal } from 'lucide-react'
import Icono from '../ui/Icono'
import { useSesion } from '../../hooks/useSesion'
import { useNavegacion } from '../../hooks/useNavegacion'
import './NavegacionInferior.css'

const DESTINOS = {
  CLIENTE: [['/catalogo','Catálogo'], ['/carrito','Carrito'], ['/mis-pedidos','Pedidos'], ['/mi-cuenta','Resumen']],
  PRODUCTOR: [['/productor','Mi panel'], ['/productor/producciones','Producción'], ['/productor/ventas','Ventas']],
  PERSONAL_FEDER: [['/gestion/pedidos','Pedidos'], ['/gestion/entregas','Entregas'], ['/gestion/pagos','Cobros'], ['/gestion/inventario','Inventario']],
  ADMINISTRADOR: [['/admin/usuarios','Usuarios'], ['/admin/bitacora','Bitácora'], ['/panel','Panel']],
}

export default function NavegacionInferior({ alAbrirMenu, bajoMenu }) {
  const { sesion } = useSesion()
  const { entradas, entradaDeRuta, cargando } = useNavegacion()
  const { pathname } = useLocation()
  if (cargando || !sesion) return null
  const activa = entradaDeRuta(pathname)?.ruta
  const destinos = DESTINOS[sesion.rol_activo.codigo] ?? []
  return <nav className="nav-inferior no-imprimir" aria-label="Navegación inferior" inert={bajoMenu || undefined}>
    {destinos.map(([ruta, etiqueta]) => {
      const entrada = entradas.find((e) => e.ruta === ruta)
      return entrada && <Link key={ruta} to={ruta} className="nav-inferior__acceso"
        aria-current={activa === ruta ? 'page' : undefined}><Icono nombre={entrada.icono} tamano={18} /><span>{etiqueta}</span></Link>
    })}
    <button type="button" className="nav-inferior__acceso" onClick={alAbrirMenu} aria-expanded={bajoMenu}
      aria-controls="menu-lateral"><MoreHorizontal size={18} /><span>Más</span></button>
  </nav>
}
