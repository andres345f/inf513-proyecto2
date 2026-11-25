import './Panel.css'

/** Selector de rango de fechas del panel. Vacío = rango por defecto del servidor. */
export default function RangoFechas({ desde, hasta, alCambiar }) {
  return (
    <form className="rango-fechas" aria-label="Rango de fechas" onSubmit={(e) => e.preventDefault()} noValidate>
      <label>Desde<input type="date" value={desde} max={hasta || undefined} onChange={(e) => alCambiar({ desde: e.target.value, hasta })} /></label>
      <label>Hasta<input type="date" value={hasta} min={desde || undefined} onChange={(e) => alCambiar({ desde, hasta: e.target.value })} /></label>
    </form>
  )
}
