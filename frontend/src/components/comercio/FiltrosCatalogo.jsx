import { useEffect, useState } from 'react'
import MensajeCampo from '../ui/MensajeCampo'

/**
 * Campos de filtro del catálogo. Se renderizan dentro de <FiltrosBarra>
 * (que aporta el contenedor, el role="search" y el botón «Limpiar»).
 * Los valores viven en la URL para poder compartir o recargar la búsqueda.
 */
export default function FiltrosCatalogo({ valores, opciones, alCambiar, rangoInvalido = false }) {
  const [texto, setTexto] = useState(valores.q || '')
  const [qAnterior, setQAnterior] = useState(valores.q || '')

  // Si los filtros se limpian desde fuera (botón «Limpiar»), el campo queda vacío.
  // (Actualización en fase de render: React la reaplica antes de pintar.)
  if ((valores.q || '') !== qAnterior) {
    setQAnterior(valores.q || '')
    setTexto(valores.q || '')
  }

  // El texto se aplica cuando el usuario deja de escribir (debounce de 400 ms).
  useEffect(() => {
    if (texto === (valores.q || '')) return undefined
    const temporizador = window.setTimeout(() => alCambiar({ q: texto }), 400)
    return () => window.clearTimeout(temporizador)
  }, [texto, valores.q, alCambiar])

  const cambiar = (evento) => {
    const { name, type, checked, value } = evento.target
    alCambiar({ [name]: type === 'checkbox' ? (checked ? '1' : '') : value })
  }

  return (
    <>
      <div className="filtros-barra__campo filtros-barra__campo--ancho">
        <label htmlFor="filtro-texto">Buscar</label>
        <input id="filtro-texto" type="search" value={texto} maxLength={60} placeholder="Nombre o categoría"
          onChange={(e) => setTexto(e.target.value)} />
      </div>

      <div className="filtros-barra__campo">
        <label htmlFor="filtro-categoria">Categoría</label>
        <select id="filtro-categoria" name="categoria" value={valores.categoria || ''} onChange={cambiar}>
          <option value="">Todas</option>
          {opciones.categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
      </div>

      <div className="filtros-barra__campo">
        <label htmlFor="filtro-comunidad">Comunidad</label>
        <select id="filtro-comunidad" name="comunidad" value={valores.comunidad || ''} onChange={cambiar}>
          <option value="">Todas</option>
          {opciones.comunidades.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
      </div>

      <div className="filtros-barra__campo filtros-barra__campo--corto">
        <label htmlFor="filtro-min">Precio desde (Bs)</label>
        <input id="filtro-min" name="precio_min" type="number" min="0" step="1" inputMode="decimal"
          value={valores.precio_min || ''} onChange={cambiar} aria-invalid={rangoInvalido || undefined} />
      </div>

      <div className="filtros-barra__campo filtros-barra__campo--corto">
        <label htmlFor="filtro-max">Precio hasta (Bs)</label>
        <input id="filtro-max" name="precio_max" type="number" min="0" step="1" inputMode="decimal"
          value={valores.precio_max || ''} onChange={cambiar} aria-invalid={rangoInvalido || undefined}
          aria-describedby={rangoInvalido ? 'filtro-max-error' : undefined} />
        <MensajeCampo id="filtro-max-error" error={rangoInvalido ? 'Debe ser mayor o igual que el precio «desde».' : null} />
      </div>

      <div className="filtros-barra__campo">
        <label htmlFor="filtro-orden">Ordenar por</label>
        <select id="filtro-orden" name="orden" value={valores.orden || 'nombre'} onChange={cambiar}>
          <option value="nombre">Nombre (A–Z)</option>
          <option value="precio_asc">Precio: menor a mayor</option>
          <option value="precio_desc">Precio: mayor a menor</option>
        </select>
      </div>

      <label className="filtros-barra__casilla">
        <input type="checkbox" name="con_stock" checked={valores.con_stock === '1'} onChange={cambiar} />
        Solo con stock
      </label>
    </>
  )
}
