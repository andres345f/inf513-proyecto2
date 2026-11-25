import { Link, useLocation } from 'react-router-dom'
import { Menu } from 'lucide-react'
import MarcaFeder from '../ui/MarcaFeder'
import { useNavegacion } from '../../hooks/useNavegacion'
import { useSesion } from '../../hooks/useSesion'
import BuscadorGlobal from './BuscadorGlobal'
import AccesoCarrito from './AccesoCarrito'
import CampanaNotificaciones from './CampanaNotificaciones'
import MenuUsuario from './MenuUsuario'
import PanelPreferencias from './PanelPreferencias'
import './Encabezado.css'

export default function Encabezado({ compacto, inerte, alAbrirMenu }) {
  const { entradas, entradaDeRuta } = useNavegacion()
  const { sesion, cargando } = useSesion()
  const { pathname } = useLocation()
  const enlaces = entradas.filter((e) => !sesion && e.ruta !== '/')
  return <header className="encabezado" inert={inerte || undefined}>
    {compacto && <button id="alternar-menu" className="encabezado__icono" type="button" onClick={alAbrirMenu}
      aria-label="Abrir menú" title="Abrir menú" aria-expanded={inerte} aria-controls="menu-lateral"><Menu size={21} /></button>}
    <Link className="encabezado__logo" to="/" aria-label="FEDER, inicio" aria-current={pathname === '/' ? 'page' : undefined}>
      <MarcaFeder />
    </Link>
    {!compacto && <nav className="encabezado__publico" aria-label="Tienda">
      <Link to="/" aria-current={pathname === '/' ? 'page' : undefined}>Inicio</Link>{enlaces.map((e) =>
      <Link key={e.ruta} to={e.ruta} aria-current={entradaDeRuta(pathname)?.ruta === e.ruta ? 'page' : undefined}>{e.etiqueta}</Link>)}</nav>}
    <BuscadorGlobal compacto={compacto} />
    <div className="encabezado__acciones">
      {!compacto && entradas.some((e) => e.ruta === '/carrito') && <AccesoCarrito key={sesion?.usuario.id} />}
      {!compacto && <PanelPreferencias />}
      {!cargando && <><CampanaNotificaciones /><MenuUsuario compacto={compacto} /></>}
    </div>
  </header>
}
