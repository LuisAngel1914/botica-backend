import pandas as pd
import numpy as np
from sklearn.linear_model import LinearRegression
import mysql.connector
import json
from datetime import datetime

# Configuración de Conexión a la BD
db_config = {
    'host': '127.0.0.1',
    'user': 'root',
    'password': '',
    'database': 'botica_lyl'
}

def obtener_datos_ventas():
    try:
        conn = mysql.connector.connect(**db_config)
        query = """
            SELECT 
                dv.producto_id,
                p.nombre AS producto_nombre,
                dv.cantidad,
                v.created_at AS fecha_venta
            FROM detalle_ventas dv
            JOIN ventas v ON dv.venta_id = v.id
            JOIN productos p ON dv.producto_id = p.id
        """
        df = pd.read_sql(query, conn)
        conn.close()
        return df
    except Exception as e:
        print(f"Error al conectar con la base de datos: {e}")
        return pd.DataFrame()

def analizar_rotacion_y_prediccion():
    df = obtener_datos_ventas()
    if df.empty:
        print("No hay suficientes datos para procesar.")
        return

    df['fecha_venta'] = pd.to_datetime(df['fecha_venta'])
    df['mes_anio'] = df['fecha_venta'].dt.to_period('M')

    # 1. Análisis de Rotación por Producto
    rotacion = df.groupby(['producto_id', 'producto_nombre'])['cantidad'].sum().reset_index()
    rotacion = rotacion.sort_values(by='cantidad', ascending=False)
    
    total_vendido = rotacion['cantidad'].sum()
    rotacion['porcentaje_acumulado'] = (rotacion['cantidad'].cumsum() / total_vendido) * 100

    # Clasificación ABC
    def clasificar_abc(pct):
        if pct <= 70:
            return 'A (Alta Rotación)'
        elif pct <= 90:
            return 'B (Rotación Media)'
        else:
            return 'C (Baja Rotación)'

    rotacion['categoria_abc'] = rotacion['porcentaje_acumulado'].apply(clasificar_abc)

    # 2. Predicción de Demanda Futura (Siguiente Mes)
    predicciones = []
    
    for prod_id, group in df.groupby('producto_id'):
        prod_nombre = group['producto_nombre'].iloc[0]
        ventas_mensuales = group.groupby('mes_anio')['cantidad'].sum().reset_index()
        ventas_mensuales['mes_index'] = np.arange(len(ventas_mensuales))

        if len(ventas_mensuales) > 1:
            X = ventas_mensuales[['mes_index']]
            y = ventas_mensuales['cantidad']
            
            model = LinearRegression()
            model.fit(X, y)
            
            proximo_mes_idx = np.array([[len(ventas_mensuales)]])
            prediccion_cant = max(0, int(np.round(model.predict(proximo_mes_idx)[0])))
        else:
            prediccion_cant = int(ventas_mensuales['cantidad'].iloc[0])

        predicciones.append({
            'producto_id': int(prod_id),
            'producto': prod_nombre,
            'demanda_proyectada_mes_siguiente': prediccion_cant
        })

    df_pred = pd.DataFrame(predicciones)
    resultado_final = pd.merge(rotacion[['producto_id', 'producto_nombre', 'cantidad', 'categoria_abc']], df_pred, on='producto_id')
    
    print("\n--- INFORME DE INTELIGENCIA DE NEGOCIO (BOTICA L Y L) ---")
    print(resultado_final.to_string(index=False))
    
    # Exportar resultados a JSON para consumo del backend/dashboard
    resultado_final.to_json('ai_engine/reporte_ia.json', orient='records', indent=4)
    print("\nReporte generado exitosamente en 'ai_engine/reporte_ia.json'.")

if __name__ == '__main__':
    analizar_rotacion_y_prediccion()