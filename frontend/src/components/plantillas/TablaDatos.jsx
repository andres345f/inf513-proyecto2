import { useState } from 'react'
import './TablaDatos.css'

const camposDetalle = new Set(['fecha', 'vence', 'tipo', 'modalidad', 'presentacion', 'descripcion', 'produccion_codigo'])

export default function TablaDatos({
  columnas = [],
  filas = [],
  claveFila = (fila) => fila.id,
  alClicFila = null,
  etiqueta = 'Registros',
  comparativa = false,
  className = '',
}) {
  const [filaAbierta, setFilaAbierta] = useState(null)
  const plegable = !comparativa && columnas.length >= 6 && columnas.some((c, i) => i > 0 && camposDetalle.has(c.clave))
  return (
    <div className={`tabla-datos ${comparativa ? 'tabla-datos--comparativa' : 'tabla-datos--registros'} ${className}`.trim()}
      tabIndex={0} role="region" aria-label={etiqueta}>
      <table aria-label={etiqueta}>
        <thead><tr>
          {columnas.map((c) => <th key={c.clave} scope="col"><span className={c.claseCabecera}>{c.etiqueta}</span></th>)}
          {plegable && <th scope="col" className="tabla-datos__cabecera-detalle">Detalle</th>}
          {alClicFila && <th scope="col">Acción</th>}
        </tr></thead>
        <tbody>
          {filas.map((fila) => (
            <tr key={claveFila(fila)} data-abierta={filaAbierta === claveFila(fila)}>
              {columnas.map((c, indice) => (
                <td key={c.clave} className={c.clase} data-columna={c.clave} data-secundaria={plegable && indice > 0 && camposDetalle.has(c.clave) || undefined}>
                  <span className="tabla-datos__etiqueta" aria-hidden="true">{c.etiqueta || 'Acciones'}</span>
                  <div className="tabla-datos__valor">{c.alRender ? c.alRender(fila) : fila[c.clave]}</div>
                </td>
              ))}
              {plegable && <td className="tabla-datos__mas"><button type="button" aria-expanded={filaAbierta === claveFila(fila)}
                onClick={() => setFilaAbierta(filaAbierta === claveFila(fila) ? null : claveFila(fila))}>
                {filaAbierta === claveFila(fila) ? 'Ocultar detalle' : 'Ver detalle'}<span className="solo-lector"> de {fila.codigo || fila.codigo_lote || claveFila(fila)}</span>
              </button></td>}
              {alClicFila && <td><button type="button" onClick={() => alClicFila(fila)}>Abrir {fila.codigo ?? claveFila(fila)}</button></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
