import { Link } from 'react-router-dom'
import { UNIDADES } from '../../utils/etiquetas'
import { formatearMoneda, formatearNumero } from '../../utils/formato'
import './LineasImporte.css'

// Las líneas históricas pueden llevar el código de unidad al final de la descripción.
function descripcionVisible(descripcion) {
  return descripcion?.replace(/\(([A-Z]+)\)$/, (original, codigo) => UNIDADES[codigo] ? `(${UNIDADES[codigo].corta})` : original)
}

export default function LineasImporte({ documento, enlazarProductos = false }) {
  return (
    <div className="lineas-importe">
      <table aria-label="Productos e importes">
        <thead><tr>
          <th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio unitario</th><th scope="col">Subtotal</th>
        </tr></thead>
        <tbody>{documento.lineas.map((linea, indice) => (
          <tr key={linea.producto_id ?? indice}>
            <th scope="row">{enlazarProductos ? <Link to={`/catalogo/${linea.producto_id}`}>{descripcionVisible(linea.descripcion)}</Link> : descripcionVisible(linea.descripcion)}</th>
            <td><span aria-hidden="true">Cantidad</span>{formatearNumero(linea.cantidad)}</td>
            <td><span aria-hidden="true">Precio unitario</span>{formatearMoneda(linea.precio)}</td>
            <td><span aria-hidden="true">Subtotal</span>{formatearMoneda(linea.subtotal)}</td>
          </tr>
        ))}</tbody>
        <tfoot>{[['Subtotal', documento.subtotal], ['Envío', documento.costo_envio], ['Total', documento.total]].map(([etiqueta, importe]) => (
          <tr key={etiqueta}><th scope="row" colSpan={3}>{etiqueta}</th><td>{formatearMoneda(importe)}</td></tr>
        ))}</tfoot>
      </table>
    </div>
  )
}
