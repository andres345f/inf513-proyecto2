import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Outlet, useLocation, useNavigationType } from 'react-router-dom'
import Encabezado from '../components/layout/Encabezado'
import MenuLateral from '../components/layout/MenuLateral'
import NavegacionInferior from '../components/layout/NavegacionInferior'
import PiePagina from '../components/layout/PiePagina'
import { useTituloDocumento } from '../hooks/useTituloDocumento'
import { useSesion } from '../hooks/useSesion'
import { usePreferencias } from '../hooks/usePreferencias'
import './LayoutPrincipal.css'

function anchoPagina(ruta) {
  if (/^\/(ingresar|registro|perfil|mi-solicitud|mis-facturas\/[^/]+)(\/|$)/.test(ruta)) return 'lectura'
  if (/^\/(gestion|admin|operaciones|administracion|panel|indicadores|reportes)(\/|$)/.test(ruta)) return 'amplio'
  return 'estandar'
}

export default function LayoutPrincipal() {
  const { sesion } = useSesion()
  const { escala } = usePreferencias()
  const consulta = escala >= 130 ? '(max-width: 1279px)' : '(max-width: 960px)'
  const [movil, setMovil] = useState(() => window.matchMedia(consulta).matches)
  const [abierto, setAbierto] = useState(false)
  const retorno = useRef(null)
  const devolver = useRef(false)
  const contenido = useRef(null)
  const ubicacion = useLocation()
  const tipoNavegacion = useNavigationType()
  const anterior = useRef(ubicacion)
  const posiciones = useRef(new Map())
  const { pathname } = ubicacion
  useTituloDocumento(contenido, pathname)
  const tieneLateral = Boolean(sesion) && !['/ingresar', '/registro'].includes(pathname)
  const modal = abierto && movil

  useEffect(() => {
    const medio = window.matchMedia(consulta)
    const actualizar = () => {
      setMovil(medio.matches)
      if (!medio.matches) {
        setAbierto(false)
        if (document.activeElement?.closest('#menu-lateral')) {
          requestAnimationFrame(() => (document.querySelector('#menu-lateral a[aria-current], #menu-lateral a') || contenido.current)?.focus())
        }
      }
    }
    actualizar()
    medio.addEventListener('change', actualizar)
    return () => medio.removeEventListener('change', actualizar)
  }, [consulta])

  useLayoutEffect(() => {
    const previa = anterior.current
    if (previa.key === ubicacion.key) return undefined
    posiciones.current.set(previa.key, window.scrollY)
    anterior.current = ubicacion
    if (previa.pathname === pathname) return undefined
    setAbierto(false)
    const guardada = tipoNavegacion === 'POP' ? posiciones.current.get(ubicacion.key) : undefined
    const principal = contenido.current
    function posicionar() {
      const titulo = principal?.querySelector('h1')
      if (!titulo) return false
      titulo.tabIndex = -1
      titulo.focus({ preventScroll: true })
      window.scrollTo({ top: guardada ?? 0, behavior: 'instant' })
      return true
    }
    if (posicionar() || !principal) return undefined
    principal.focus({ preventScroll: true })
    const observador = new MutationObserver(() => { if (posicionar()) observador.disconnect() })
    observador.observe(principal, { subtree: true, childList: true })
    return () => observador.disconnect()
  }, [ubicacion, pathname, tipoNavegacion])

  useEffect(() => {
    if (!modal) {
      if (devolver.current) {
        devolver.current = false
        retorno.current?.focus()
      }
      return undefined
    }
    const panel = document.getElementById('menu-lateral')
    const anteriorOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panel.querySelector('button')?.focus()
    const teclado = (e) => {
      if (e.key === 'Escape' && !e.defaultPrevented) {
        e.preventDefault()
        devolver.current = true
        setAbierto(false)
      }
      if (e.key !== 'Tab') return
      const controles = Array.from(panel.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled)'))
        .filter((el) => el.offsetParent !== null)
      const primero = controles[0], ultimo = controles.at(-1)
      if (e.shiftKey && document.activeElement === primero) { e.preventDefault(); ultimo?.focus() }
      else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primero?.focus() }
    }
    document.addEventListener('keydown', teclado)
    return () => { document.body.style.overflow = anteriorOverflow; document.removeEventListener('keydown', teclado) }
  }, [modal])

  function abrir(e) { retorno.current = e.currentTarget; setAbierto(true) }
  function cerrar() { devolver.current = true; setAbierto(false) }
  return <div className="layout" data-compacto={movil} data-con-barra={Boolean(sesion) && movil}>
    <a href="#contenido" className="saltar-contenido" inert={modal || undefined}>Saltar al contenido</a>
    <Encabezado compacto={movil} inerte={modal} alAbrirMenu={abrir} />
    <div className="layout__cuerpo">
      {(movil || tieneLateral) && <MenuLateral key={sesion?.rol_activo.id ?? 'publico'} abierto={modal} movil={movil}
        alCerrar={cerrar} alNavegar={() => setAbierto(false)} />}
      {modal && <div className="layout__velo" onClick={cerrar} aria-hidden="true" />}
      <main id="contenido" ref={contenido} className="layout__contenido" tabIndex={-1} inert={modal || undefined}>
        <div className="layout__pagina" data-ancho={anchoPagina(pathname)}><Outlet /></div>
      </main>
    </div>
    {sesion && movil && <NavegacionInferior alAbrirMenu={abrir} bajoMenu={modal} />}
    <PiePagina inerte={modal} />
  </div>
}
