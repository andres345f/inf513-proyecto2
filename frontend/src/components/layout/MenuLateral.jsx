import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ChevronDown, X } from 'lucide-react'
import { useNavegacion } from '../../hooks/useNavegacion'
import { useSesion } from '../../hooks/useSesion'
import { nombreRol } from '../../utils/formato'
import Icono from '../ui/Icono'
import PanelPreferencias from './PanelPreferencias'
import { etiquetaTarea, organizarMenu } from './organizarMenu'
import './MenuLateral.css'

function Destino({ entrada, activa, alNavegar }) {
  return <Link to={entrada.ruta} aria-current={entrada.ruta === activa ? 'page' : undefined}
    className="menu-lateral__enlace" onClick={alNavegar}>
    <Icono nombre={entrada.icono} tamano={18} /><span>{etiquetaTarea(entrada)}</span>
  </Link>
}

function Grupo({ bloque, activa, alNavegar }) {
  const actual = bloque.entradas.some((entrada) => entrada.ruta === activa)
  const [abierto, alternar] = useState(actual)
  return <li>
    <button type="button" className="menu-lateral__grupo" aria-expanded={abierto}
      onClick={() => alternar(!abierto)}>{bloque.titulo}<ChevronDown size={16} aria-hidden="true" /></button>
    {abierto && <ul className="menu-lateral__hijos">{bloque.entradas.map((entrada) =>
      <li key={entrada.ruta}><Destino entrada={entrada} activa={activa} alNavegar={alNavegar} /></li>)}</ul>}
  </li>
}

export default function MenuLateral({ abierto, movil, alNavegar, alCerrar }) {
  const { entradas, cargando, error, reintentar, entradaDeRuta } = useNavegacion()
  const { sesion } = useSesion()
  const { pathname } = useLocation()
  const activa = entradaDeRuta(pathname)?.ruta
  const bloques = organizarMenu(entradas)
  const publicas = movil ? entradas.filter((e) => e.ruta === '/' || e.ruta === '/catalogo')
    : sesion ? entradas.filter((e) => e.ruta === '/catalogo') : []
  return <aside id="menu-lateral" className="menu-lateral" hidden={movil && !abierto}
    role={movil ? 'dialog' : undefined} aria-modal={movil && abierto || undefined}
    aria-label={movil ? 'Menú de navegación' : 'Tareas del contexto'}>
    <div className="menu-lateral__interior">
    <div className="menu-lateral__cabecera"><strong>{sesion ? nombreRol(sesion.rol_activo.codigo) : 'FEDER'}</strong>
      {movil && <button type="button" className="menu-lateral__cerrar" onClick={alCerrar} aria-label="Cerrar menú"><X size={20} /></button>}
    </div>
    <nav aria-label="Menú principal">
      {cargando && <p role="status">Cargando menú…</p>}
      {error && <p role="alert">{error} <button type="button" onClick={reintentar}>Reintentar</button></p>}
      <ul className="menu-lateral__lista">
        {publicas.map((e) => <li key={e.ruta}><Destino entrada={e} activa={activa} alNavegar={alNavegar} /></li>)}
        {bloques.map((bloque) => bloque.titulo
          ? <Grupo key={bloque.titulo + (bloque.entradas.some((e) => e.ruta === activa) ? '-activo' : '')} bloque={bloque} activa={activa} alNavegar={alNavegar} />
          : <li key={bloque.entradas[0].ruta}><Destino entrada={bloque.entradas[0]} activa={activa} alNavegar={alNavegar} /></li>)}
        {!sesion && movil && <li><Link to="/registro" onClick={alNavegar} className="menu-lateral__enlace">Crear cuenta</Link></li>}
      </ul>
    </nav>
    {movil && <PanelPreferencias enMenu />}
    </div>
  </aside>
}
