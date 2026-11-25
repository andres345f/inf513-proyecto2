import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search, X } from 'lucide-react'
import { useBusquedaGlobal } from '../../hooks/useBusquedaGlobal'
import { formatearMoneda } from '../../utils/formato'
import './BuscadorGlobal.css'

function opcionesDe(resultados) {
  if (!resultados) return []
  return [
    ...resultados.productos.map((p) => ({ grupo: 'Productos', nombre: p.nombre, detalle: [p.detalle, formatearMoneda(p.precio)].filter(Boolean).join(' · '), ruta: '/catalogo/' + p.id })),
    ...resultados.categorias.map((c) => ({ grupo: 'Categorías', nombre: c.nombre, ruta: '/catalogo?categoria=' + c.id })),
    ...resultados.comunidades.map((c) => ({ grupo: 'Comunidades', nombre: c.nombre, detalle: c.detalle, ruta: '/catalogo?comunidad=' + c.id })),
  ]
}

/** El foco sigue en el campo. aria-activedescendant identifica la opción recorrida con flechas. */
export default function BuscadorGlobal({ compacto }) {
  const [texto, setTexto] = useState('')
  const [sugerencias, setSugerencias] = useState(false)
  const [capa, setCapa] = useState(false)
  const [indice, setIndice] = useState(-1)
  const campo = useRef(null)
  const dialogo = useRef(null)
  const disparador = useRef(null)
  const navegar = useNavigate()
  const { habilitada, cargando, resultados, error } = useBusquedaGlobal(texto)
  const opciones = opcionesDe(resultados)
  const abierto = sugerencias && habilitada

  useEffect(() => {
    const ventana = dialogo.current
    if (compacto && capa) {
      ventana?.showModal()
      campo.current?.focus()
    }
    return () => { if (ventana?.open) ventana.close() }
  }, [compacto, capa])

  function cerrarCapa() {
    dialogo.current?.close()
    setCapa(false)
    setSugerencias(false)
    setIndice(-1)
    disparador.current?.focus()
  }

  function elegir(ruta) {
    if (compacto) cerrarCapa()
    setSugerencias(false)
    setIndice(-1)
    setTexto('')
    navegar(ruta)
  }

  function alTeclado(e) {
    if (e.key === 'Escape' && abierto) {
      e.preventDefault()
      e.stopPropagation()
      setSugerencias(false)
      setIndice(-1)
      return
    }
    if (!['ArrowDown','ArrowUp'].includes(e.key) || !opciones.length) return
    e.preventDefault()
    setSugerencias(true)
    const proximo = e.key === 'ArrowDown' ? (indice + 1) % opciones.length : (indice <= 0 ? opciones.length - 1 : indice - 1)
    setIndice(proximo)
    requestAnimationFrame(() => document.getElementById('busqueda-opcion-' + proximo)?.scrollIntoView({block:'nearest'}))
  }

  const formulario = <div className="buscador__contenido" onBlur={(e) => {
    if (!e.currentTarget.contains(e.relatedTarget)) { setSugerencias(false); setIndice(-1) }
  }}>
    <form role="search" className="buscador__formulario" onSubmit={(e) => {
      e.preventDefault()
      if (abierto && opciones[indice]) elegir(opciones[indice].ruta)
      else if (habilitada) elegir('/catalogo?q=' + encodeURIComponent(texto.trim()))
    }}>
      <label htmlFor="buscador-global" className="solo-lector">Buscar productos, categorías o comunidades</label>
      <Search className="buscador__icono" size={18} aria-hidden="true" />
      <input ref={campo} id="buscador-global" type="search" role="combobox" aria-autocomplete="list" autoComplete="off"
        className="buscador__campo" placeholder="Buscar productos, categorías o comunidades…" value={texto} maxLength={60}
        aria-controls="buscador-resultados" aria-expanded={abierto}
        aria-activedescendant={abierto && opciones[indice] ? 'busqueda-opcion-' + indice : undefined}
        onKeyDown={alTeclado} onFocus={() => setSugerencias(true)}
        onChange={(e) => { setTexto(e.target.value); setIndice(-1); setSugerencias(true) }} />
    </form>
    <div className="buscador__panel" hidden={!abierto}>
      <p className="buscador__estado" role="status">{cargando ? 'Buscando…' : error ||
        (resultados && !opciones.length ? 'No encontramos resultados para «' + texto.trim() + '».' : '')}</p>
      <ul id="buscador-resultados" role="listbox" aria-label="Resultados de búsqueda" className="buscador__lista">
        {opciones.map((opcion,i) => <li key={opcion.ruta} id={'busqueda-opcion-' + i} role="option" aria-selected={i === indice}
          className="buscador__resultado" onMouseDown={(e) => e.preventDefault()} onClick={() => elegir(opcion.ruta)}>
          <strong>{opcion.nombre}</strong><span>{opcion.grupo}{opcion.detalle && ' · ' + opcion.detalle}</span>
        </li>)}
      </ul>
    </div>
  </div>

  return <div className="buscador">
    {compacto ? <>
      <button ref={disparador} type="button" aria-label="Buscar en el catálogo" title="Buscar en el catálogo" aria-haspopup="dialog"
        className="encabezado__icono" onClick={() => setCapa(true)}><Search size={19} /></button>
      <dialog ref={dialogo} className="buscador__dialogo" aria-label="Buscar en el catálogo" onCancel={(e) => {e.preventDefault(); cerrarCapa()}}>
        <div className="buscador__cabecera"><strong>Buscar en el catálogo</strong><button className="encabezado__icono"
          type="button" onClick={cerrarCapa} aria-label="Cerrar búsqueda"><X size={20} /></button></div>{formulario}
      </dialog>
    </> : formulario}
  </div>
}
