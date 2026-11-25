import Alerta from '../ui/Alerta'

import Cargando from '../ui/Cargando'

import Paginacion from '../ui/Paginacion'

import EncabezadoPagina from './EncabezadoPagina'

import EstadoVacio from './EstadoVacio'

import FiltrosBarra from './FiltrosBarra'

import './PaginaListado.css'

/**
 * Armazón estándar de las páginas de listado (pedidos, facturas, productos…):
 * encabezado + filtros + aviso de error + resumen + cuerpo + paginación.
 * Las páginas solo aportan sus datos y sus ítems; la estructura es única.
 *
 * - titulo / descripcion / accionPrincipal: ver EncabezadoPagina.
 * - filtros: nodo con los campos; se envuelve en FiltrosBarra.
 * - etiquetaFiltros / alLimpiarFiltros: accesibilidad y botón «Limpiar».
 * - resumen: texto tipo «24 pedidos» mostrado sobre la lista.
 * - cargando / error: estados de la carga.
 * - vacio: { titulo, mensaje, accion, icono } → EstadoVacio cuando no hay datos.
 * - paginacion: { pagina, paginas, alCambiar } → Paginacion del sistema.
 * - children: la lista o tabla propiamente dicha.
 */
export default function PaginaListado({
  titulo,
  descripcion,
  accionPrincipal = null,
  filtros = null,
  etiquetaFiltros = 'Filtrar resultados',
  alLimpiarFiltros = null,
  filtrosActivos = 0,
  alReintentar = null,
  actualizando = false,
  resumen = null,
  cargando = false,
  error = null,
  vacio = null,
  paginacion = null,
  className = '',
  children,
}) {
  const sinDatos = Boolean(vacio) && !cargando && !error

  return (
    <section className={`pagina-listado ${className}`.trim()} aria-busy={cargando || actualizando}>
      <EncabezadoPagina titulo={titulo} descripcion={descripcion} accion={accionPrincipal} />

      {filtros && (
        <FiltrosBarra etiqueta={etiquetaFiltros} activos={filtrosActivos} alLimpiar={alLimpiarFiltros}>
          {filtros}
        </FiltrosBarra>
      )}

      {error && <Alerta tipo="error">{error} {alReintentar && <button type="button" onClick={alReintentar}>Reintentar</button>}</Alerta>}
      {actualizando && <p className="pagina-listado__resumen" role="status">Actualizando…</p>}

      {resumen && !cargando && !error && !sinDatos && (
        <div className="pagina-listado__resumen" aria-live="polite">{resumen}</div>
      )}

      <div className="pagina-listado__cuerpo">
        {cargando && <Cargando />}
        {!cargando && !error && (sinDatos ? <EstadoVacio {...vacio} /> : children)}
      </div>

      {paginacion && !cargando && !error && !sinDatos && (
        <Paginacion pagina={paginacion.pagina} paginas={paginacion.paginas} alCambiar={paginacion.alCambiar} />
      )}
    </section>
  )
}
